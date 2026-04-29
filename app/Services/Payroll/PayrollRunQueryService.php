<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use App\PermissionName;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class PayrollRunQueryService
{
    /**
     * @param  array{month?: string|null, status?: string|null, per_page?: int|string|null}  $filters
     */
    public function paginate(array $filters = [], ?User $authenticatedUser = null): LengthAwarePaginator
    {
        if ($authenticatedUser !== null) {
            $this->ensureRunViewer($authenticatedUser);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return PayrollRun::query()
            ->when(
                filled($filters['month'] ?? null),
                fn ($query) => $query->whereDate(
                    'payroll_month',
                    Carbon::createFromFormat('Y-m', (string) $filters['month'])->startOfMonth()->toDateString(),
                )
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->orderByDesc('payroll_month')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(PayrollRun $payrollRun, ?User $authenticatedUser = null): PayrollRun
    {
        if ($authenticatedUser !== null) {
            $this->ensureRunViewer($authenticatedUser);
        }

        return $payrollRun->load([
            'items' => fn ($query) => $query->orderBy('employee_name_snapshot')->orderBy('id'),
        ]);
    }

    private function ensureRunViewer(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->can(PermissionName::PayrollRunView->value)) {
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
