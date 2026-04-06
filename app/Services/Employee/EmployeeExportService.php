<?php

namespace App\Services\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Employee\Exports\EmployeeExcelExporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class EmployeeExportService
{
    public function __construct(
        private EmployeeService $employeeService,
        private EmployeeExcelExporter $employeeExcelExporter,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{path: string, filename: string, content_type: string}
     */
    public function exportExcel(?User $authenticatedUser, array $filters = []): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $this->ensureCanExport($authenticatedUser);

        $report = $this->buildReport($filters);
        $filename = 'employees_'.now()->format('Y_m_d_His').'.xlsx';
        $path = $this->temporaryPath($filename);

        $this->employeeExcelExporter->store($path, $report);

        $this->auditLogService->log(
            'employee',
            'employee.export',
            'employee.export',
            $authenticatedUser,
            null,
            [
                'filters' => $filters,
                'exported_by_user_id' => $authenticatedUser->id,
                'exported_count' => count($report['records']),
                'exported_at' => now()->toIso8601String(),
            ],
        );

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
     *     filter_summary: array<int, string>,
     *     records: array<int, array<string, string|null>>
     * }
     */
    private function buildReport(array $filters): array
    {
        $records = $this->employeeService->filteredQuery($filters)
            ->get()
            ->map(fn (Employee $employee): array => $this->transformRecord($employee))
            ->values()
            ->all();

        return [
            'title' => 'Employees Export',
            'generated_at' => now()->toDateTimeString(),
            'filter_summary' => $this->filterSummary($filters),
            'records' => $records,
        ];
    }

    private function ensureAuthenticated(?User $authenticatedUser): User
    {
        if ($authenticatedUser === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $authenticatedUser;
    }

    private function ensureCanExport(User $authenticatedUser): void
    {
        if ($authenticatedUser->roles()->where('name', 'hr')->exists()) {
            return;
        }

        throw new HttpException(403, 'Forbidden.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function filterSummary(array $filters): array
    {
        $summary = [];

        if (isset($filters['search'])) {
            $summary[] = 'Search: '.$filters['search'];
        }

        if (isset($filters['status'])) {
            $summary[] = 'Status: '.$filters['status'];
        }

        if (isset($filters['department_id'])) {
            $department = Department::query()->find($filters['department_id']);

            if ($department instanceof Department) {
                $summary[] = 'Department: '.$department->name;
            }
        }

        if (isset($filters['branch_id'])) {
            $summary[] = 'Branch ID: '.$filters['branch_id'];
        }

        if (isset($filters['current_position_id'])) {
            $position = Position::query()->find($filters['current_position_id']);

            if ($position instanceof Position) {
                $summary[] = 'Current Position: '.$position->title;
            }
        }

        if (isset($filters['manager_id'])) {
            $manager = Employee::query()->find($filters['manager_id']);

            if ($manager instanceof Employee) {
                $summary[] = 'Manager: '.$manager->full_name;
            }
        }

        if (isset($filters['employment_type'])) {
            $summary[] = 'Employment Type: '.$filters['employment_type'];
        }

        if (isset($filters['hire_date_from']) || isset($filters['hire_date_to'])) {
            $summary[] = 'Hire Date Range: '.($filters['hire_date_from'] ?? 'n/a').' to '.($filters['hire_date_to'] ?? 'n/a');
        }

        if (isset($filters['sort_by'])) {
            $summary[] = 'Sort: '.$filters['sort_by'].' '.($filters['sort_direction'] ?? 'desc');
        }

        return $summary;
    }

    /**
     * @return array<string, string|null>
     */
    private function transformRecord(Employee $employee): array
    {
        return [
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'department' => $employee->department?->name,
            'current_position' => $employee->currentPosition?->title,
            'manager' => $employee->manager?->full_name,
            'hire_date' => $employee->hire_date?->toDateString(),
            'employment_type' => $employee->employment_type?->value,
            'status' => $employee->status?->value,
        ];
    }

    private function temporaryPath(string $filename): string
    {
        $directory = storage_path('app/exports/employees');

        File::ensureDirectoryExists($directory);

        return $directory.'/'.Str::uuid()->toString().'-'.$filename;
    }
}
