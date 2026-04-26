<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class OwnPayslipService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * @param  array{month?: string|null, status?: string|null, per_page?: int|string|null}  $filters
     */
    public function paginate(?User $authenticatedUser, array $filters = []): LengthAwarePaginator
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return PayrollItem::query()
            ->with('payrollRun')
            ->where('employee_id', $employee->id)
            ->when(
                filled($filters['month'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'payrollRun',
                    fn (Builder $runQuery): Builder => $runQuery->whereDate(
                        'payroll_month',
                        Carbon::createFromFormat('Y-m', (string) $filters['month'])->startOfMonth()->toDateString(),
                    )
                )
            )
            ->when(
                filled($filters['status'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'payrollRun',
                    fn (Builder $runQuery): Builder => $runQuery->where('status', $filters['status'])
                )
            )
            ->orderByDesc('payroll_run_id')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(?User $authenticatedUser, PayrollItem $payrollItem): PayrollItem
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $payrollItem = $payrollItem->loadMissing('payrollRun');

        if ($payrollItem->employee_id !== $employee->id) {
            throw new HttpException(403, 'Forbidden.');
        }

        $this->auditLogService->log(
            'payroll',
            'payslip_viewed',
            'payroll.payslip_viewed',
            $authenticatedUser,
            $payrollItem,
            [
                'payroll_item_id' => $payrollItem->id,
                'payroll_run_id' => $payrollItem->payroll_run_id,
                'employee_id' => $payrollItem->employee_id,
            ],
        );

        return $payrollItem;
    }

    private function ensureAuthenticated(?User $authenticatedUser): User
    {
        if ($authenticatedUser === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $authenticatedUser;
    }

    private function ensureEmployeeProfile(User $authenticatedUser): Employee
    {
        $employee = $authenticatedUser->loadMissing('employee')->employee;

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages([
                'user' => ['The authenticated user is not linked to an employee profile.'],
            ]);
        }

        return $employee;
    }
}
