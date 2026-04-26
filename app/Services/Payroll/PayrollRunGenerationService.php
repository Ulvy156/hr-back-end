<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollRunGenerationService
{
    private const INVALID_GENERATION_MESSAGE = 'Cannot generate payroll. No eligible employees or working days found for the selected month.';

    public function __construct(
        private AuditLogService $auditLogService,
        private EmployeeSalaryResolver $employeeSalaryResolver,
        private PayrollCalculationService $payrollCalculationService,
    ) {}

    /**
     * @return array{
     *     month_start: Carbon,
     *     month_end: Carbon,
     *     employees: Collection<int, Employee>,
     *     errors: array<int, array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}>,
     *     blocking_message: string|null
     * }
     */
    public function prepareGeneration(string $payrollMonth, ?int $ignorePayrollRunId = null): array
    {
        [$monthStart, $monthEnd] = $this->normalizePayrollMonth($payrollMonth);

        if (
            PayrollRun::query()
                ->whereDate('payroll_month', $monthStart->toDateString())
                ->when(
                    $ignorePayrollRunId !== null,
                    fn (Builder $query): Builder => $query->whereKeyNot($ignorePayrollRunId)
                )
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'month' => ['A payroll run already exists for the selected month.'],
            ]);
        }

        $employees = $this->includedEmployees($monthStart, $monthEnd);

        if ($employees->isEmpty()) {
            return [
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
                'employees' => $employees,
                'errors' => [],
                'blocking_message' => self::INVALID_GENERATION_MESSAGE,
            ];
        }

        $blockingMessage = $this->blockingMessageForPreparedGeneration($employees, $monthStart);

        if ($blockingMessage !== null) {
            return [
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
                'employees' => $employees,
                'errors' => [],
                'blocking_message' => $blockingMessage,
            ];
        }

        $errors = $employees
            ->map(function (Employee $employee) use ($monthEnd, $monthStart): ?array {
                $salaryReferenceDate = $this->salaryReferenceDateForEmployee($employee, $monthStart, $monthEnd);
                $resolvedSalary = $this->employeeSalaryResolver->resolveForDate($employee, $salaryReferenceDate);

                if (($resolvedSalary['salary_source'] ?? 'missing') === 'missing' || $resolvedSalary['amount'] === null) {
                    return $this->salaryError($employee, 'No valid salary found for selected month.');
                }

                if ((float) $resolvedSalary['amount'] <= 0) {
                    return $this->salaryError($employee, 'Resolved salary amount must be greater than zero.');
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();

        return [
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'employees' => $employees,
            'errors' => $errors,
            'blocking_message' => null,
        ];
    }

    /**
     * @param  array{
     *     month_start: Carbon,
     *     month_end: Carbon,
     *     employees: Collection<int, Employee>,
     *     errors: array<int, array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}>,
     *     blocking_message: string|null
     * }  $preparedGeneration
     */
    public function generatePrepared(array $preparedGeneration, ?User $actor = null): PayrollRun
    {
        if ($preparedGeneration['errors'] !== []) {
            throw ValidationException::withMessages([
                'month' => ['Payroll generation contains blocking validation errors.'],
            ]);
        }

        if (($preparedGeneration['blocking_message'] ?? null) !== null) {
            throw $this->invalidGenerationValidationException();
        }

        $monthStart = $preparedGeneration['month_start'];
        $compiledGeneration = $this->compileGenerationPayload($preparedGeneration);

        try {
            return DB::transaction(function () use ($actor, $compiledGeneration, $monthStart): PayrollRun {
                $payrollRun = PayrollRun::query()->create([
                    'payroll_month' => $monthStart->toDateString(),
                    'status' => 'draft',
                    ...$compiledGeneration['totals'],
                ]);

                $payrollRun->items()->createMany($compiledGeneration['items']);

                if ($actor !== null) {
                    $this->auditLogService->log(
                        'payroll',
                        'payroll_generated',
                        'payroll.generated',
                        $actor,
                        $payrollRun,
                        [
                            'payroll_run_id' => $payrollRun->id,
                            'payroll_month' => $payrollRun->payroll_month?->toDateString(),
                            'employee_count' => $payrollRun->employee_count,
                            'total_net_salary' => $payrollRun->total_net_salary,
                        ],
                    );
                }

                return $payrollRun->fresh() ?? $payrollRun;
            });
        } catch (QueryException $exception) {
            if (PayrollRun::query()->whereDate('payroll_month', $monthStart->toDateString())->exists()) {
                throw ValidationException::withMessages([
                    'month' => ['A payroll run already exists for the selected month.'],
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array{
     *     month_start: Carbon,
     *     month_end: Carbon,
     *     employees: Collection<int, Employee>,
     *     errors: array<int, array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}>,
     *     blocking_message: string|null
     * }  $preparedGeneration
     */
    public function rebuildRun(PayrollRun $payrollRun, array $preparedGeneration): PayrollRun
    {
        if (($preparedGeneration['blocking_message'] ?? null) !== null) {
            throw $this->invalidGenerationValidationException();
        }

        $compiledGeneration = $this->compileGenerationPayload($preparedGeneration);

        $payrollRun->items()->delete();
        $payrollRun->items()->createMany($compiledGeneration['items']);

        $payrollRun->forceFill([
            ...$compiledGeneration['totals'],
        ])->save();

        return $payrollRun->fresh() ?? $payrollRun;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function normalizePayrollMonth(string $payrollMonth): array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $payrollMonth)->startOfMonth();

        return [$monthStart, $monthStart->copy()->endOfMonth()];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function includedEmployees(Carbon $monthStart, Carbon $monthEnd): Collection
    {
        return Employee::query()
            ->with([
                'employeeDependents' => fn ($query) => $query
                    ->select([
                        'id',
                        'employee_id',
                        'relationship',
                        'date_of_birth',
                        'is_active',
                        'is_working',
                        'is_student',
                        'is_claimed_for_tax',
                    ])
                    ->where('is_active', true)
                    ->where('is_claimed_for_tax', true),
            ])
            ->whereDate('hire_date', '<=', $monthEnd->toDateString())
            ->where(function (Builder $query) use ($monthStart): void {
                $query
                    ->where(function (Builder $subQuery): void {
                        $subQuery->whereNull('last_working_date')
                            ->whereNull('termination_date');
                    })
                    ->orWhereDate('last_working_date', '>=', $monthStart->toDateString())
                    ->orWhere(function (Builder $subQuery) use ($monthStart): void {
                        $subQuery->whereNull('last_working_date')
                            ->whereDate('termination_date', '>=', $monthStart->toDateString());
                    });
            })
            ->orderBy('employee_code')
            ->orderBy('id')
            ->get();
    }

    private function salaryReferenceDateForEmployee(Employee $employee, Carbon $monthStart, Carbon $monthEnd): Carbon
    {
        $employmentEnd = $employee->last_working_date?->copy()->startOfDay()
            ?? $employee->termination_date?->copy()->startOfDay()
            ?? $monthEnd->copy()->startOfDay();

        return $employmentEnd->lessThan($monthEnd)
            ? $employmentEnd
            : $monthEnd->copy()->startOfDay();
    }

    /**
     * @return array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}
     */
    private function salaryError(Employee $employee, string $reason): array
    {
        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->full_name,
            'reason' => $reason,
        ];
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function blockingMessageForPreparedGeneration(Collection $employees, Carbon $monthStart): ?string
    {
        /** @var Employee|null $firstEmployee */
        $firstEmployee = $employees->first();

        if (! $firstEmployee instanceof Employee) {
            return self::INVALID_GENERATION_MESSAGE;
        }

        try {
            $sampleCalculation = $this->payrollCalculationService->calculateForEmployee($firstEmployee, $monthStart);
        } catch (ValidationException $exception) {
            if ($this->isWorkingTimeValidationException($exception)) {
                return self::INVALID_GENERATION_MESSAGE;
            }

            throw $exception;
        }

        if ((int) $sampleCalculation['company_working_days'] < 1 || (int) $sampleCalculation['monthly_working_hours'] < 1) {
            return self::INVALID_GENERATION_MESSAGE;
        }

        return null;
    }

    /**
     * @param  array{
     *     month_start: Carbon,
     *     month_end: Carbon,
     *     employees: Collection<int, Employee>,
     *     errors: array<int, array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}>,
     *     blocking_message: string|null
     * }  $preparedGeneration
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     totals: array{
     *         company_working_days: int,
     *         monthly_working_hours: int,
     *         employee_count: int,
     *         total_base_salary: float,
     *         total_prorated_base_salary: float,
     *         total_overtime_pay: float,
     *         total_unpaid_leave_deduction: float,
     *         total_tax_amount: float,
     *         total_net_salary: float
     *     }
     * }
     */
    private function compileGenerationPayload(array $preparedGeneration): array
    {
        /** @var Collection<int, Employee> $employees */
        $employees = $preparedGeneration['employees'];
        $monthStart = $preparedGeneration['month_start'];

        $totals = [
            'company_working_days' => 0,
            'monthly_working_hours' => 0,
            'employee_count' => $employees->count(),
            'total_base_salary' => 0.0,
            'total_prorated_base_salary' => 0.0,
            'total_overtime_pay' => 0.0,
            'total_unpaid_leave_deduction' => 0.0,
            'total_tax_amount' => 0.0,
            'total_net_salary' => 0.0,
        ];
        $items = [];

        foreach ($employees as $employee) {
            try {
                $calculation = $this->payrollCalculationService->calculateForEmployee($employee, $monthStart);
            } catch (ValidationException $exception) {
                if ($this->isWorkingTimeValidationException($exception)) {
                    throw $this->invalidGenerationValidationException();
                }

                throw $exception;
            }

            $items[] = [
                'employee_id' => $employee->id,
                'employee_salary_id' => $calculation['employee_salary_id'],
                'salary_source' => $calculation['salary_source'],
                'employee_code_snapshot' => $employee->employee_code,
                'employee_name_snapshot' => $employee->full_name,
                'base_salary' => $calculation['base_salary'],
                'prorated_base_salary' => $calculation['prorated_base_salary'],
                'hourly_rate' => $calculation['hourly_rate'],
                'daily_rate' => $calculation['daily_rate'],
                'eligible_working_days' => $calculation['eligible_working_days'],
                'company_working_days' => $calculation['company_working_days'],
                'monthly_working_hours' => $calculation['monthly_working_hours'],
                'overtime_normal_hours' => $calculation['overtime_normal_hours'],
                'overtime_weekend_hours' => $calculation['overtime_weekend_hours'],
                'overtime_holiday_hours' => $calculation['overtime_holiday_hours'],
                'overtime_pay' => $calculation['overtime_pay'],
                'unpaid_leave_units' => $calculation['unpaid_leave_units'],
                'unpaid_leave_deduction' => $calculation['unpaid_leave_deduction'],
                'tax_amount' => $calculation['tax_amount'],
                'raw_net_salary' => $calculation['gross_net_salary'],
                'net_salary' => $calculation['net_salary'],
            ];

            $totals['company_working_days'] = (int) $calculation['company_working_days'];
            $totals['monthly_working_hours'] = (int) $calculation['monthly_working_hours'];
            $totals['total_base_salary'] += (float) $calculation['base_salary'];
            $totals['total_prorated_base_salary'] += (float) $calculation['prorated_base_salary'];
            $totals['total_overtime_pay'] += (float) $calculation['overtime_pay'];
            $totals['total_unpaid_leave_deduction'] += (float) $calculation['unpaid_leave_deduction'];
            $totals['total_tax_amount'] += (float) $calculation['tax_amount'];
            $totals['total_net_salary'] += (float) $calculation['net_salary'];
        }

        $this->assertCompilableTotals($totals);

        return [
            'items' => $items,
            'totals' => [
                'company_working_days' => $totals['company_working_days'],
                'monthly_working_hours' => $totals['monthly_working_hours'],
                'employee_count' => $totals['employee_count'],
                'total_base_salary' => round($totals['total_base_salary'], 2),
                'total_prorated_base_salary' => round($totals['total_prorated_base_salary'], 2),
                'total_overtime_pay' => round($totals['total_overtime_pay'], 2),
                'total_unpaid_leave_deduction' => round($totals['total_unpaid_leave_deduction'], 2),
                'total_tax_amount' => round($totals['total_tax_amount'], 2),
                'total_net_salary' => round($totals['total_net_salary'], 2),
            ],
        ];
    }

    /**
     * @param  array{
     *     company_working_days: int,
     *     monthly_working_hours: int,
     *     employee_count: int,
     *     total_base_salary: float,
     *     total_prorated_base_salary: float,
     *     total_overtime_pay: float,
     *     total_unpaid_leave_deduction: float,
     *     total_tax_amount: float,
     *     total_net_salary: float
     * }  $totals
     */
    private function assertCompilableTotals(array $totals): void
    {
        if (
            $totals['employee_count'] < 1
            || $totals['company_working_days'] < 1
            || $totals['monthly_working_hours'] < 1
        ) {
            throw $this->invalidGenerationValidationException();
        }
    }

    private function isWorkingTimeValidationException(ValidationException $exception): bool
    {
        return collect($exception->errors())
            ->flatten()
            ->contains('The selected month has no company working days.');
    }

    private function invalidGenerationValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'month' => [self::INVALID_GENERATION_MESSAGE],
        ]);
    }
}
