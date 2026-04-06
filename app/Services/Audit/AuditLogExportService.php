<?php

namespace App\Services\Audit;

use App\Services\Audit\Exports\AuditLogExcelExporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AuditLogExportService
{
    public function __construct(
        private AuditLogQueryService $auditLogQueryService,
        private AuditLogExcelExporter $auditLogExcelExporter,
    ) {}

    /**
     * @param  array{
     *     keyword?: string,
     *     log_name?: string,
     *     event?: string,
     *     causer_id?: int,
     *     subject_type?: string,
     *     subject_id?: int,
     *     batch_uuid?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     per_page?: int
     * }  $filters
     * @return array{path: string, filename: string, content_type: string}
     */
    public function exportExcel(array $filters = []): array
    {
        $report = [
            'title' => 'Audit Logs Export',
            'generated_at' => now()->toDateTimeString(),
            'filter_summary' => $this->filterSummary($filters),
            'records' => $this->auditLogQueryService->filteredQuery($filters)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursor()
                ->map(fn ($activity): array => $this->auditLogQueryService->transformRecord($activity))
                ->all(),
        ];

        $filename = 'audit-logs-'.now()->format('Y-m-d').'.xlsx';
        $path = $this->temporaryPath($filename);

        $this->auditLogExcelExporter->store($path, $report);

        return [
            'path' => $path,
            'filename' => $filename,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function filterSummary(array $filters): array
    {
        $summary = [];

        if (isset($filters['keyword'])) {
            $summary[] = 'Keyword: '.$filters['keyword'];
        }

        if (isset($filters['log_name'])) {
            $summary[] = 'Log Name: '.$filters['log_name'];
        }

        if (isset($filters['event'])) {
            $summary[] = 'Event: '.$filters['event'];
        }

        if (isset($filters['causer_id'])) {
            $summary[] = 'Causer ID: '.$filters['causer_id'];
        }

        if (isset($filters['subject_type'])) {
            $summary[] = 'Subject Type: '.$filters['subject_type'];
        }

        if (isset($filters['subject_id'])) {
            $summary[] = 'Subject ID: '.$filters['subject_id'];
        }

        if (isset($filters['batch_uuid'])) {
            $summary[] = 'Batch UUID: '.$filters['batch_uuid'];
        }

        if (isset($filters['from_date']) || isset($filters['to_date'])) {
            $summary[] = 'Date Range: '.($filters['from_date'] ?? 'n/a').' to '.($filters['to_date'] ?? 'n/a');
        }

        return $summary;
    }

    private function temporaryPath(string $filename): string
    {
        $directory = storage_path('app/exports/audit-logs');

        File::ensureDirectoryExists($directory);

        return $directory.'/'.Str::uuid()->toString().'-'.$filename;
    }
}
