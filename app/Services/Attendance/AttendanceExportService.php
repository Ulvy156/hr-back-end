<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\PermissionName;
use App\Services\Attendance\Exports\AttendanceExcelExporter;
use App\Services\Attendance\Exports\AttendancePdfExporter;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AttendanceExportService
{
    public function __construct(
        private AttendanceService $attendanceService,
        private AttendancePdfExporter $attendancePdfExporter,
        private AttendanceExcelExporter $attendanceExcelExporter,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{path: string, filename: string, content_type: string}
     */
    public function exportPdf(?User $authenticatedUser, array $filters = []): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $report = $this->buildReport($authenticatedUser, $filters);
        $filename = $this->buildFilename($report['scope'], $report['period_key'], 'pdf');
        $path = $this->temporaryPath($filename);

        $this->attendancePdfExporter->store($path, $report);
        $this->logExport($authenticatedUser, 'pdf', $filters, $report);

        return [
            'path' => $path,
            'filename' => $filename,
            'content_type' => 'application/pdf',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{path: string, filename: string, content_type: string}
     */
    public function exportExcel(?User $authenticatedUser, array $filters = []): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $report = $this->buildReport($authenticatedUser, $filters);
        $filename = $this->buildFilename($report['scope'], $report['period_key'], 'xlsx');
        $path = $this->temporaryPath($filename);

        $this->attendanceExcelExporter->store($path, $report);
        $this->logExport($authenticatedUser, 'excel', $filters, $report);

        return [
            'path' => $path,
            'filename' => $filename,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     title: string,
     *     generated_at: string,
     *     scope: string,
     *     period_label: string,
     *     period_key: string,
     *     filter_summary: array<int, string>,
     *     summary: array<string, int>,
     *     records: array<int, array<string, string|int|null>>
     * }
     */
    private function buildReport(?User $authenticatedUser, array $filters): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $scope = $this->resolveScope($authenticatedUser);
        $filters = $this->normalizeFilters($authenticatedUser, $filters, $scope);

        $records = $this->attendanceService->filteredAttendanceQuery($filters)
            ->get()
            ->map(fn (Attendance $attendance): array => $this->transformRecord($attendance))
            ->values();

        return [
            'title' => $scope === 'self' ? 'My Attendance Report' : 'Attendance Report',
            'generated_at' => now()->toDateTimeString(),
            'scope' => $scope,
            'period_label' => $this->periodLabel($filters),
            'period_key' => $this->periodKey($filters),
            'filter_summary' => $this->filterSummary($filters),
            'summary' => $this->summarize($records),
            'records' => $records->all(),
        ];
    }

    private function ensureAuthenticated(?User $authenticatedUser): User
    {
        if ($authenticatedUser === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $authenticatedUser;
    }

    private function resolveScope(User $authenticatedUser): string
    {
        if ($authenticatedUser->can(PermissionName::AttendanceExportAny->value)) {
            return 'all';
        }

        if ($authenticatedUser->can(PermissionName::AttendanceExport->value)) {
            return 'self';
        }

        throw new HttpException(403, 'Forbidden.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(User $authenticatedUser, array $filters, string $scope): array
    {
        if (! isset($filters['month']) && ! isset($filters['from_date']) && ! isset($filters['to_date'])) {
            $filters['month'] = now()->format('Y-m');
        }

        if ($scope === 'self') {
            $employee = $authenticatedUser->loadMissing('employee')->employee;

            if (! $employee instanceof Employee) {
                throw new HttpException(403, 'Forbidden.');
            }

            $filters['employee_id'] = $employee->id;
            unset($filters['department_id']);
        }

        return $filters;
    }

    /**
     * @return array<string, int>
     */
    private function summarize(Collection $records): array
    {
        $totalRecords = $records->count();
        $completedRecords = $records->filter(
            fn (array $record): bool => $record['check_in_time'] !== null && $record['check_out_time'] !== null
        )->count();
        $lateRecords = $records->filter(fn (array $record): bool => (int) $record['late_minutes'] > 0)->count();
        $correctedRecords = $records->filter(fn (array $record): bool => $record['status'] === AttendanceStatus::Corrected)->count();
        $absentRecords = $records->filter(fn (array $record): bool => $record['status'] === AttendanceStatus::Absent)->count();
        $totalWorkedMinutes = $records->sum(fn (array $record): int => (int) $record['worked_minutes']);
        $totalOvertimeMinutes = $records->sum(fn (array $record): int => (int) $record['overtime_minutes']);

        return [
            'total_records' => $totalRecords,
            'completed_records' => $completedRecords,
            'late_records' => $lateRecords,
            'corrected_records' => $correctedRecords,
            'absent_records' => $absentRecords,
            'total_worked_minutes' => $totalWorkedMinutes,
            'total_overtime_minutes' => $totalOvertimeMinutes,
            'average_worked_minutes' => $completedRecords > 0
                ? (int) round($totalWorkedMinutes / $completedRecords)
                : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function filterSummary(array $filters): array
    {
        $summary = [];

        if (isset($filters['month'])) {
            $summary[] = 'Month: '.Carbon::createFromFormat('Y-m', $filters['month'])->format('F Y');
        }

        if (isset($filters['from_date']) || isset($filters['to_date'])) {
            $fromDate = $filters['from_date'] ?? 'n/a';
            $toDate = $filters['to_date'] ?? 'n/a';
            $summary[] = 'Date Range: '.$fromDate.' to '.$toDate;
        }

        if (isset($filters['employee_id'])) {
            $employee = Employee::query()->find($filters['employee_id']);

            if ($employee instanceof Employee) {
                $summary[] = 'Employee: '.trim($employee->first_name.' '.$employee->last_name);
            }
        }

        if (isset($filters['department_id'])) {
            $department = Department::query()->find($filters['department_id']);

            if ($department instanceof Department) {
                $summary[] = 'Department: '.$department->name;
            }
        }

        if (isset($filters['status'])) {
            $summary[] = 'Status: '.$filters['status'];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters): string
    {
        if (isset($filters['month'])) {
            return Carbon::createFromFormat('Y-m', $filters['month'])->format('F Y');
        }

        if (isset($filters['from_date']) || isset($filters['to_date'])) {
            return ($filters['from_date'] ?? 'n/a').' to '.($filters['to_date'] ?? 'n/a');
        }

        return now()->format('F Y');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodKey(array $filters): string
    {
        if (isset($filters['month'])) {
            return $filters['month'];
        }

        if (isset($filters['from_date']) || isset($filters['to_date'])) {
            return ($filters['from_date'] ?? 'start').'_to_'.($filters['to_date'] ?? 'end');
        }

        return now()->format('Y-m');
    }

    private function buildFilename(string $scope, string $periodKey, string $extension): string
    {
        $prefix = $scope === 'self' ? 'my-attendance-report' : 'attendance-report';

        return sprintf(
            '%s-%s.%s',
            $prefix,
            Str::of($periodKey)->replace([' ', '/', ':'], ['-', '-', '-']),
            $extension,
        );
    }

    private function temporaryPath(string $filename): string
    {
        $directory = storage_path('app/exports/attendance');

        File::ensureDirectoryExists($directory);

        return $directory.'/'.Str::uuid()->toString().'-'.$filename;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param array{
     *     title: string,
     *     generated_at: string,
     *     scope: string,
     *     period_label: string,
     *     period_key: string,
     *     filter_summary: array<int, string>,
     *     summary: array<string, int>,
     *     records: array<int, array<string, string|int|null>>
     * } $report
     */
    private function logExport(User $authenticatedUser, string $format, array $filters, array $report): void
    {
        $this->auditLogService->log(
            logName: 'attendance',
            event: 'exported',
            description: 'attendance.exported',
            causer: $authenticatedUser,
            properties: [
                'format' => $format,
                'scope' => $report['scope'],
                'period' => $report['period_key'],
                'record_count' => count($report['records']),
                'filters' => $filters,
            ],
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    private function transformRecord(Attendance $attendance): array
    {
        $attendance->loadMissing('employee.department');

        return [
            'employee_name' => trim(($attendance->employee?->first_name ?? '').' '.($attendance->employee?->last_name ?? '')),
            'employee_id' => $attendance->employee_id,
            'department' => $attendance->employee?->department?->name,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'check_in_time' => $attendance->check_in?->format('H:i:s'),
            'check_out_time' => $attendance->check_out?->format('H:i:s'),
            'worked_minutes' => (int) $attendance->worked_minutes,
            'worked_hours' => round(((int) $attendance->worked_minutes) / 60, 2),
            'status' => $attendance->status,
            'late_minutes' => (int) $attendance->late_minutes,
            'early_leave_minutes' => (int) $attendance->early_leave_minutes,
            'overtime_minutes' => (int) $attendance->overtime_minutes,
            'correction_status' => $attendance->correction_status,
        ];
    }
}
