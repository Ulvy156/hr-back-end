<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollRunLifecycleService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PayrollRunGenerationService $payrollRunGenerationService,
    ) {}

    public function approve(PayrollRun $payrollRun, ?User $actor = null): PayrollRun
    {
        return DB::transaction(function () use ($actor, $payrollRun): PayrollRun {
            /** @var PayrollRun $lockedPayrollRun */
            $lockedPayrollRun = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($payrollRun->id);

            if ($lockedPayrollRun->status !== PayrollRun::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft payroll runs can be approved.'],
                ]);
            }

            $lockedPayrollRun->forceFill([
                'status' => PayrollRun::STATUS_APPROVED,
            ])->save();

            if ($actor !== null) {
                $this->auditLogService->log(
                    'payroll',
                    'payroll_approved',
                    'payroll.approved',
                    $actor,
                    $lockedPayrollRun,
                    [
                        'payroll_run_id' => $lockedPayrollRun->id,
                        'payroll_month' => $lockedPayrollRun->payroll_month?->toDateString(),
                        'status' => $lockedPayrollRun->status,
                    ],
                );
            }

            return $lockedPayrollRun->fresh() ?? $lockedPayrollRun;
        });
    }

    public function markPaid(PayrollRun $payrollRun, ?User $actor = null): PayrollRun
    {
        return DB::transaction(function () use ($actor, $payrollRun): PayrollRun {
            /** @var PayrollRun $lockedPayrollRun */
            $lockedPayrollRun = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($payrollRun->id);

            if ($lockedPayrollRun->status !== PayrollRun::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => ['Only approved payroll runs can be marked as paid.'],
                ]);
            }

            $lockedPayrollRun->forceFill([
                'status' => PayrollRun::STATUS_PAID,
            ])->save();

            if ($actor !== null) {
                $this->auditLogService->log(
                    'payroll',
                    'payroll_marked_paid',
                    'payroll.marked_paid',
                    $actor,
                    $lockedPayrollRun,
                    [
                        'payroll_run_id' => $lockedPayrollRun->id,
                        'payroll_month' => $lockedPayrollRun->payroll_month?->toDateString(),
                        'status' => $lockedPayrollRun->status,
                    ],
                );
            }

            return $lockedPayrollRun->fresh() ?? $lockedPayrollRun;
        });
    }

    public function cancel(PayrollRun $payrollRun, ?User $actor = null): PayrollRun
    {
        return DB::transaction(function () use ($actor, $payrollRun): PayrollRun {
            /** @var PayrollRun $lockedPayrollRun */
            $lockedPayrollRun = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($payrollRun->id);

            if (! in_array($lockedPayrollRun->status, [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft or approved payroll runs can be cancelled.'],
                ]);
            }

            $lockedPayrollRun->forceFill([
                'status' => PayrollRun::STATUS_CANCELLED,
            ])->save();

            if ($actor !== null) {
                $this->auditLogService->log(
                    'payroll',
                    'payroll_cancelled',
                    'payroll.cancelled',
                    $actor,
                    $lockedPayrollRun,
                    [
                        'payroll_run_id' => $lockedPayrollRun->id,
                        'payroll_month' => $lockedPayrollRun->payroll_month?->toDateString(),
                        'status' => $lockedPayrollRun->status,
                    ],
                );
            }

            return $lockedPayrollRun->fresh() ?? $lockedPayrollRun;
        });
    }

    /**
     * @return array{
     *     month_start: Carbon,
     *     month_end: Carbon,
     *     employees: Collection<int, Employee>,
     *     errors: array<int, array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}>
     * }
     */
    public function prepareRegeneration(PayrollRun $payrollRun): array
    {
        $this->assertRegeneratableStatus($payrollRun);

        return $this->payrollRunGenerationService->prepareGeneration(
            $payrollRun->payroll_month?->format('Y-m') ?? '',
            $payrollRun->id,
        );
    }

    /**
     * @param  array{
     *     month_start: Carbon,
     *     month_end: Carbon,
     *     employees: Collection<int, Employee>,
     *     errors: array<int, array{employee_id: int, employee_code: string|null, employee_name: string, reason: string}>
     * }  $preparedGeneration
     */
    public function regeneratePrepared(PayrollRun $payrollRun, array $preparedGeneration, ?User $actor = null): PayrollRun
    {
        return DB::transaction(function () use ($actor, $payrollRun, $preparedGeneration): PayrollRun {
            /** @var PayrollRun $lockedPayrollRun */
            $lockedPayrollRun = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($payrollRun->id);

            $this->assertRegeneratableStatus($lockedPayrollRun);

            if ($preparedGeneration['errors'] !== []) {
                throw ValidationException::withMessages([
                    'month' => ['Payroll regeneration contains blocking validation errors.'],
                ]);
            }

            $regeneratedPayrollRun = $this->payrollRunGenerationService->rebuildRun(
                $lockedPayrollRun,
                $preparedGeneration,
            );

            if ($actor !== null) {
                $this->auditLogService->log(
                    'payroll',
                    'payroll_regenerated',
                    'payroll.regenerated',
                    $actor,
                    $regeneratedPayrollRun,
                    [
                        'payroll_run_id' => $regeneratedPayrollRun->id,
                        'payroll_month' => $regeneratedPayrollRun->payroll_month?->toDateString(),
                        'status' => $regeneratedPayrollRun->status,
                        'employee_count' => $regeneratedPayrollRun->employee_count,
                    ],
                );
            }

            return $regeneratedPayrollRun;
        });
    }

    private function assertRegeneratableStatus(PayrollRun $payrollRun): void
    {
        if ($payrollRun->status === PayrollRun::STATUS_DRAFT) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => ['Only draft payroll runs can be regenerated.'],
        ]);
    }
}
