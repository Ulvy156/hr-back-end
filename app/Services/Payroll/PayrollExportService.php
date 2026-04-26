<?php

namespace App\Services\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Payroll\Exports\PayrollRunExcelExporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class PayrollExportService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PayrollRunExcelExporter $payrollRunExcelExporter,
        private PayrollRunQueryService $payrollRunQueryService,
    ) {}

    /**
     * @return array{path: string, filename: string, content_type: string}
     */
    public function exportExcel(?User $authenticatedUser, PayrollRun $payrollRun): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $report = $this->buildReport($payrollRun);
        $filename = $this->buildFilename($payrollRun);
        $path = $this->temporaryPath($filename);

        $this->payrollRunExcelExporter->store($path, $report);
        $this->logExport($authenticatedUser, $payrollRun, $report);

        return [
            'path' => $path,
            'filename' => $filename,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     generated_at: string,
     *     payroll_month: string,
     *     status: string,
     *     summary: array<string, string|int>,
     *     records: array<int, array<string, string>>
     * }
     */
    private function buildReport(PayrollRun $payrollRun): array
    {
        $payrollRun = $this->payrollRunQueryService->find($payrollRun);

        return [
            'title' => 'Payroll Export',
            'generated_at' => now()->toDateTimeString(),
            'payroll_month' => $payrollRun->payroll_month?->format('F Y') ?? '',
            'status' => $payrollRun->status,
            'summary' => [
                'employee_count' => $payrollRun->employee_count,
                'total_base_salary' => $this->decimal((string) $payrollRun->total_base_salary),
                'total_prorated_base_salary' => $this->decimal((string) $payrollRun->total_prorated_base_salary),
                'total_overtime_pay' => $this->decimal((string) $payrollRun->total_overtime_pay),
                'total_unpaid_leave_deduction' => $this->decimal((string) $payrollRun->total_unpaid_leave_deduction),
                'total_tax_amount' => $this->decimal((string) $payrollRun->total_tax_amount),
                'total_net_salary' => $this->decimal((string) $payrollRun->total_net_salary),
            ],
            'records' => $payrollRun->items
                ->map(fn (PayrollItem $item): array => $this->transformRecord($payrollRun, $item))
                ->values()
                ->all(),
        ];
    }

    private function ensureAuthenticated(?User $authenticatedUser): User
    {
        if ($authenticatedUser === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $authenticatedUser;
    }

    private function buildFilename(PayrollRun $payrollRun): string
    {
        return sprintf(
            'payroll-run-%s.xlsx',
            $payrollRun->payroll_month?->format('Y-m') ?? Str::uuid()->toString(),
        );
    }

    private function temporaryPath(string $filename): string
    {
        $directory = storage_path('app/exports/payroll');

        File::ensureDirectoryExists($directory);

        return $directory.'/'.Str::uuid()->toString().'-'.$filename;
    }

    /**
     * @param  array{
     *     title: string,
     *     generated_at: string,
     *     payroll_month: string,
     *     status: string,
     *     summary: array<string, string|int>,
     *     records: array<int, array<string, string>>
     * }  $report
     */
    private function logExport(User $authenticatedUser, PayrollRun $payrollRun, array $report): void
    {
        $this->auditLogService->log(
            logName: 'payroll',
            event: 'payroll_exported',
            description: 'payroll.exported',
            causer: $authenticatedUser,
            subject: $payrollRun,
            properties: [
                'format' => 'excel',
                'payroll_run_id' => $payrollRun->id,
                'payroll_month' => $payrollRun->payroll_month?->toDateString(),
                'status' => $payrollRun->status,
                'record_count' => count($report['records']),
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function transformRecord(PayrollRun $payrollRun, PayrollItem $item): array
    {
        return [
            'payroll_month' => $payrollRun->payroll_month?->toDateString() ?? '',
            'status' => $payrollRun->status,
            'employee_code' => $item->employee_code_snapshot ?? '',
            'employee_name' => $item->employee_name_snapshot,
            'base_salary' => $this->decimal((string) $item->base_salary),
            'prorated_base_salary' => $this->decimal((string) $item->prorated_base_salary),
            'overtime_normal_hours' => $this->hours((string) $item->overtime_normal_hours),
            'overtime_weekend_hours' => $this->hours((string) $item->overtime_weekend_hours),
            'overtime_holiday_hours' => $this->hours((string) $item->overtime_holiday_hours),
            'overtime_pay' => $this->decimal((string) $item->overtime_pay),
            'unpaid_leave_units' => $this->hours((string) $item->unpaid_leave_units),
            'unpaid_leave_deduction' => $this->decimal((string) $item->unpaid_leave_deduction),
            'tax_amount' => $this->decimal((string) $item->tax_amount),
            'net_salary' => $this->decimal((string) $item->net_salary),
        ];
    }

    private function decimal(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function hours(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
