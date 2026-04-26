<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PayrollRunQueryService
{
    /**
     * @param  array{month?: string|null, status?: string|null, per_page?: int|string|null}  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
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

    public function find(PayrollRun $payrollRun): PayrollRun
    {
        return $payrollRun->load([
            'items' => fn ($query) => $query->orderBy('employee_name_snapshot')->orderBy('id'),
        ]);
    }
}
