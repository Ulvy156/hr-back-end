<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\PermissionName;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class EmployeeSalaryService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private EmployeeSalaryCompatibilityService $employeeSalaryCompatibilityService,
    ) {}

    /**
     * @param  array{
     *     employee_id?: int|string|null,
     *     status?: 'current'|'ended'|'all'|null,
     *     effective_date?: string|null,
     *     effective_on?: string|null
     * }  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15, ?User $authenticatedUser = null): LengthAwarePaginator
    {
        if ($authenticatedUser !== null) {
            $this->ensureSalaryReader($authenticatedUser);
        }

        return EmployeeSalary::query()
            ->with('employee')
            ->when(
                filled($filters['employee_id'] ?? null),
                fn (Builder $query): Builder => $query->where('employee_id', (int) $filters['employee_id'])
            )
            ->when(
                ($filters['status'] ?? null) === 'current',
                fn (Builder $query): Builder => $query->whereNull('end_date')
            )
            ->when(
                ($filters['status'] ?? null) === 'ended',
                fn (Builder $query): Builder => $query->whereNotNull('end_date')
            )
            ->when(
                filled($filters['effective_date'] ?? null),
                fn (Builder $query): Builder => $query->whereDate('effective_date', (string) $filters['effective_date'])
            )
            ->when(
                filled($filters['effective_on'] ?? null),
                fn (Builder $query): Builder => $query->activeOn((string) $filters['effective_on'])
            )
            ->orderBy('employee_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{employee_id: int, amount: int|float|string, effective_date: string, end_date?: string|null}  $data
     */
    public function create(array $data, ?User $actor = null): EmployeeSalary
    {
        $actor = $this->ensureSalaryManager($actor);

        return DB::transaction(function () use ($actor, $data): EmployeeSalary {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($data['employee_id']);

            $effectiveDate = Carbon::parse($data['effective_date'])->startOfDay();
            $endDate = array_key_exists('end_date', $data) && $data['end_date'] !== null
                ? Carbon::parse($data['end_date'])->startOfDay()
                : null;

            $this->assertValidDateRange($effectiveDate, $endDate);
            $replacedCurrentSalary = $this->endReplaceableCurrentSalary(
                $employee,
                $effectiveDate,
            );
            $this->assertNoOverlappingSalaryPeriods($employee->id, $effectiveDate, $endDate);

            $employeeSalary = $employee->employeeSalaries()->create([
                'amount' => $data['amount'],
                'effective_date' => $effectiveDate->toDateString(),
                'end_date' => $endDate?->toDateString(),
            ]);

            $syncedPosition = $this->employeeSalaryCompatibilityService
                ->syncCurrentSalaryToEmployeePosition($employeeSalary);

            $employeeSalary->load('employee');

            if ($actor !== null) {
                if ($replacedCurrentSalary instanceof EmployeeSalary) {
                    $this->auditLogService->log(
                        'payroll',
                        'payroll_salary_ended',
                        'payroll.salary.ended',
                        $actor,
                        $replacedCurrentSalary,
                        [
                            'employee_salary_id' => $replacedCurrentSalary->id,
                            'employee_id' => $replacedCurrentSalary->employee_id,
                            'effective_date' => $replacedCurrentSalary->effective_date?->toDateString(),
                            'end_date' => $replacedCurrentSalary->end_date?->toDateString(),
                            'replacement_employee_salary_id' => $employeeSalary->id,
                            'replacement_effective_date' => $employeeSalary->effective_date?->toDateString(),
                        ],
                    );
                }

                $this->auditLogService->log(
                    'payroll',
                    'payroll_salary_created',
                    'payroll.salary.created',
                    $actor,
                    $employeeSalary,
                    [
                        'employee_salary_id' => $employeeSalary->id,
                        'employee_id' => $employeeSalary->employee_id,
                        'amount' => $employeeSalary->amount,
                        'effective_date' => $employeeSalary->effective_date?->toDateString(),
                        'end_date' => $employeeSalary->end_date?->toDateString(),
                        'compatibility_synced' => $syncedPosition !== null,
                        'compatibility_employee_position_id' => $syncedPosition?->id,
                    ],
                );
            }

            return $employeeSalary;
        });
    }

    /**
     * @param  array{amount?: int|float|string, effective_date?: string, end_date?: string|null}  $data
     */
    public function update(EmployeeSalary $employeeSalary, array $data, ?User $actor = null): EmployeeSalary
    {
        $actor = $this->ensureSalaryManager($actor);

        return DB::transaction(function () use ($actor, $data, $employeeSalary): EmployeeSalary {
            /** @var EmployeeSalary $lockedEmployeeSalary */
            $lockedEmployeeSalary = EmployeeSalary::query()
                ->whereKey($employeeSalary->id)
                ->with('employee')
                ->lockForUpdate()
                ->firstOrFail();

            $effectiveDate = array_key_exists('effective_date', $data)
                ? Carbon::parse($data['effective_date'])->startOfDay()
                : $lockedEmployeeSalary->effective_date?->copy()->startOfDay();
            $endDate = array_key_exists('end_date', $data)
                ? ($data['end_date'] !== null ? Carbon::parse($data['end_date'])->startOfDay() : null)
                : $lockedEmployeeSalary->end_date?->copy()->startOfDay();

            if (! $effectiveDate instanceof Carbon) {
                throw ValidationException::withMessages([
                    'effective_date' => ['The effective date is required.'],
                ]);
            }

            $this->assertValidDateRange($effectiveDate, $endDate);
            $this->assertNoOverlappingSalaryPeriods(
                $lockedEmployeeSalary->employee_id,
                $effectiveDate,
                $endDate,
                $lockedEmployeeSalary->id,
            );

            $lockedEmployeeSalary->fill([
                'amount' => $data['amount'] ?? $lockedEmployeeSalary->amount,
                'effective_date' => $effectiveDate->toDateString(),
                'end_date' => $endDate?->toDateString(),
            ])->save();

            $syncedPosition = $this->employeeSalaryCompatibilityService
                ->syncCurrentSalaryToEmployeePosition($lockedEmployeeSalary);

            $lockedEmployeeSalary->load('employee');

            if ($actor !== null) {
                $this->auditLogService->log(
                    'payroll',
                    'payroll_salary_updated',
                    'payroll.salary.updated',
                    $actor,
                    $lockedEmployeeSalary,
                    [
                        'employee_salary_id' => $lockedEmployeeSalary->id,
                        'employee_id' => $lockedEmployeeSalary->employee_id,
                        'amount' => $lockedEmployeeSalary->amount,
                        'effective_date' => $lockedEmployeeSalary->effective_date?->toDateString(),
                        'end_date' => $lockedEmployeeSalary->end_date?->toDateString(),
                        'compatibility_synced' => $syncedPosition !== null,
                        'compatibility_employee_position_id' => $syncedPosition?->id,
                    ],
                );
            }

            return $lockedEmployeeSalary;
        });
    }

    private function endReplaceableCurrentSalary(Employee $employee, Carbon $effectiveDate): ?EmployeeSalary
    {
        /** @var EmployeeSalary|null $currentSalary */
        $currentSalary = $employee->employeeSalaries()
            ->activeOn(today())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $currentSalary instanceof EmployeeSalary) {
            return null;
        }

        if ($currentSalary->effective_date === null) {
            return null;
        }

        if ($currentSalary->end_date !== null && $currentSalary->end_date->lt($effectiveDate)) {
            return null;
        }

        if ($effectiveDate->lte($currentSalary->effective_date->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'effective_date' => ['The effective date must be later than the employee\'s current salary effective date.'],
            ]);
        }

        $currentSalary->forceFill([
            'end_date' => $effectiveDate->copy()->subDay()->toDateString(),
        ])->save();

        return $currentSalary->refresh();
    }

    private function assertNoOverlappingSalaryPeriods(
        int $employeeId,
        Carbon $effectiveDate,
        ?Carbon $endDate,
        ?int $ignoreEmployeeSalaryId = null,
    ): void {
        $overlapExists = EmployeeSalary::query()
            ->where('employee_id', $employeeId)
            ->when(
                $ignoreEmployeeSalaryId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($ignoreEmployeeSalaryId)
            )
            ->whereDate('effective_date', '<=', $endDate?->toDateString() ?? '9999-12-31')
            ->where(function (Builder $query) use ($effectiveDate): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $effectiveDate->toDateString());
            })
            ->exists();

        if (! $overlapExists) {
            return;
        }

        throw ValidationException::withMessages([
            'effective_date' => ['The salary period overlaps an existing salary record for this employee.'],
        ]);
    }

    private function assertValidDateRange(Carbon $effectiveDate, ?Carbon $endDate): void
    {
        if ($endDate === null || $endDate->gte($effectiveDate)) {
            return;
        }

        throw ValidationException::withMessages([
            'end_date' => ['The end date must be on or after the effective date.'],
        ]);
    }

    private function ensureSalaryReader(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->can(PermissionName::PayrollSalaryView->value)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function ensureSalaryManager(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->can(PermissionName::PayrollSalaryManage->value)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function ensureAuthenticated(?User $authenticatedUser): User
    {
        if ($authenticatedUser === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $authenticatedUser;
    }
}
