<?php

namespace App\Services\Dashboard;

use App\EmployeeStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class DashboardService
{
    /**
     * @return array{
     *     role: string,
     *     summary: array<string, mixed>,
     *     quickActions: array<int, array{key: string, label: string}>,
     *     recentRecords: array<int, array<string, mixed>>,
     *     extra: array<string, mixed>
     * }
     */
    public function show(?User $authenticatedUser): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $role = $this->resolveRole($authenticatedUser);

        return match ($role) {
            'admin' => $this->buildAdminDashboard($authenticatedUser),
            'hr' => $this->buildHrDashboard($authenticatedUser),
            'manager' => $this->buildEmployeeDashboard($authenticatedUser, 'manager'),
            default => $this->buildEmployeeDashboard($authenticatedUser, 'employee'),
        };
    }

    /**
     * @return array{
     *     role: string,
     *     summary: array<string, mixed>,
     *     quickActions: array<int, array{key: string, label: string}>,
     *     recentRecords: array<int, array<string, mixed>>,
     *     extra: array<string, mixed>
     * }
     */
    private function buildEmployeeDashboard(User $authenticatedUser, string $role): array
    {
        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $todayAttendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();

        $todaySummary = $this->getTodayAttendanceSummary($todayAttendance);

        return [
            'role' => $role,
            'summary' => [
                ...$todaySummary,
                'attendanceThisWeek' => $this->getWeeklyAttendanceSummary($employee),
            ],
            'quickActions' => [
                ['key' => 'scan_attendance', 'label' => 'Scan Attendance'],
                ['key' => 'view_attendance_history', 'label' => 'Attendance History'],
                ['key' => 'change_password', 'label' => 'Change Password'],
            ],
            'recentRecords' => $this->getEmployeeRecentRecords($employee),
            'extra' => (object) [],
        ];
    }

    /**
     * @return array{
     *     role: string,
     *     summary: array<string, mixed>,
     *     quickActions: array<int, array{key: string, label: string}>,
     *     recentRecords: array<int, array<string, mixed>>,
     *     extra: array<string, mixed>
     * }
     */
    private function buildHrDashboard(User $authenticatedUser): array
    {
        return [
            'role' => 'hr',
            'summary' => $this->getWorkforceSummary(),
            'quickActions' => [
                ['key' => 'manage_employees', 'label' => 'Manage Employees'],
                ['key' => 'review_attendance', 'label' => 'Review Attendance'],
                ['key' => 'attendance_history', 'label' => 'Attendance History'],
            ],
            'recentRecords' => $this->getWorkforceRecentRecords(),
            'extra' => [
                'attendanceIssues' => $this->getAttendanceIssues(),
            ],
        ];
    }

    /**
     * @return array{
     *     role: string,
     *     summary: array<string, mixed>,
     *     quickActions: array<int, array{key: string, label: string}>,
     *     recentRecords: array<int, array<string, mixed>>,
     *     extra: array<string, mixed>
     * }
     */
    private function buildAdminDashboard(User $authenticatedUser): array
    {
        return [
            'role' => 'admin',
            'summary' => [
                ...$this->getWorkforceSummary(),
                'totalUsers' => User::query()->count(),
                'usersByRole' => $this->getUsersByRole(),
            ],
            'quickActions' => [
                ['key' => 'manage_users', 'label' => 'Manage Users'],
                ['key' => 'manage_employees', 'label' => 'Manage Employees'],
                ['key' => 'correct_attendance', 'label' => 'Correct Attendance'],
            ],
            'recentRecords' => $this->getWorkforceRecentRecords(),
            'extra' => [
                'attendanceIssues' => $this->getAttendanceIssues(),
            ],
        ];
    }

    /**
     * @return array{
     *     todayAttendanceStatus: string,
     *     checkInTime: string|null,
     *     checkOutTime: string|null,
     *     nextAction: string
     * }
     */
    private function getTodayAttendanceSummary(?Attendance $attendance): array
    {
        $status = 'not_checked_in';
        $nextAction = 'scan_in';

        if ($attendance?->check_in !== null && $attendance->check_out !== null) {
            $status = 'checked_out';
            $nextAction = 'none';
        } elseif ($attendance?->check_in !== null) {
            $status = 'checked_in';
            $nextAction = 'scan_out';
        }

        return [
            'todayAttendanceStatus' => $status,
            'checkInTime' => $this->formatTime($attendance?->check_in),
            'checkOutTime' => $this->formatTime($attendance?->check_out),
            'nextAction' => $nextAction,
        ];
    }

    /**
     * @return array{totalPresentDays: int, lateCount: int}
     */
    private function getWeeklyAttendanceSummary(Employee $employee): array
    {
        $weekAttendances = Attendance::query()
            ->select(['attendance_date', 'check_in', 'status'])
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', now()->startOfWeek()->toDateString())
            ->whereDate('attendance_date', '<=', now()->endOfWeek()->toDateString())
            ->get();

        return [
            'totalPresentDays' => $weekAttendances
                ->filter(fn (Attendance $attendance): bool => $attendance->check_in !== null)
                ->count(),
            'lateCount' => $weekAttendances
                ->filter(fn (Attendance $attendance): bool => $this->isLateAttendance($attendance))
                ->count(),
        ];
    }

    /**
     * @return array{
     *     totalEmployees: int,
     *     activeEmployees: int,
     *     checkedInTodayCount: int,
     *     checkedOutTodayCount: int,
     *     missingAttendanceCount: int,
     *     lateCountToday: int,
     *     employeesOnLeaveTodayCount: int
     * }
     */
    private function getWorkforceSummary(): array
    {
        $totalEmployees = Employee::query()->count();
        $activeEmployees = $this->activeEmployeesQuery()->count();
        $checkedInTodayCount = $this->todayAttendanceBaseQuery()
            ->whereNotNull('attendances.check_in')
            ->distinct()
            ->count('attendances.employee_id');
        $checkedOutTodayCount = $this->todayAttendanceBaseQuery()
            ->whereNotNull('attendances.check_in')
            ->whereNotNull('attendances.check_out')
            ->distinct()
            ->count('attendances.employee_id');
        $lateCountToday = $this->todayAttendanceBaseQuery()
            ->whereNotNull('attendances.check_in')
            ->where('attendances.check_in', '>', $this->todayWorkStart())
            ->distinct()
            ->count('attendances.employee_id');

        return [
            'totalEmployees' => $totalEmployees,
            'activeEmployees' => $activeEmployees,
            'checkedInTodayCount' => $checkedInTodayCount,
            'checkedOutTodayCount' => $checkedOutTodayCount,
            'missingAttendanceCount' => max($activeEmployees - $checkedOutTodayCount, 0),
            'lateCountToday' => $lateCountToday,
            'employeesOnLeaveTodayCount' => $this->getEmployeesOnLeaveTodayCount(),
        ];
    }

    /**
     * @return array{missingCheckout: int, lateArrivals: int, incompleteAttendance: int}
     */
    private function getAttendanceIssues(): array
    {
        $missingCheckout = $this->todayAttendanceBaseQuery()
            ->whereNotNull('attendances.check_in')
            ->whereNull('attendances.check_out')
            ->distinct()
            ->count('attendances.employee_id');

        $incompleteAttendance = $this->todayAttendanceBaseQuery()
            ->where(function (Builder $query): void {
                $query->whereNull('attendances.check_in')
                    ->orWhereNull('attendances.check_out');
            })
            ->distinct()
            ->count('attendances.employee_id');

        return [
            'missingCheckout' => $missingCheckout,
            'lateArrivals' => $this->todayAttendanceBaseQuery()
                ->whereNotNull('attendances.check_in')
                ->where('attendances.check_in', '>', $this->todayWorkStart())
                ->distinct()
                ->count('attendances.employee_id'),
            'incompleteAttendance' => $incompleteAttendance,
        ];
    }

    /**
     * @return array<int, array{date: string, checkInTime: string|null, checkOutTime: string|null, status: string}>
     */
    private function getEmployeeRecentRecords(Employee $employee): array
    {
        return Attendance::query()
            ->select(['attendance_date', 'check_in', 'check_out', 'status'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in')
            ->limit((int) config('dashboard.employee_recent_limit', 5))
            ->get()
            ->map(fn (Attendance $attendance): array => [
                'date' => $attendance->attendance_date?->toDateString() ?? '',
                'checkInTime' => $this->formatTime($attendance->check_in),
                'checkOutTime' => $this->formatTime($attendance->check_out),
                'status' => $attendance->status,
            ])
            ->all();
    }

    /**
     * @return array<int, array{
     *     date: string,
     *     checkInTime: string|null,
     *     checkOutTime: string|null,
     *     status: string,
     *     employee: array{id: int, name: string, department: string|null}
     * }>
     */
    private function getWorkforceRecentRecords(): array
    {
        return Attendance::query()
            ->select(['id', 'employee_id', 'attendance_date', 'check_in', 'check_out', 'status'])
            ->with([
                'employee:id,department_id,first_name,last_name',
                'employee.department:id,name',
            ])
            ->whereHas('employee', fn (Builder $query): Builder => $query->where('status', EmployeeStatus::Active->value))
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in')
            ->limit((int) config('dashboard.workforce_recent_limit', 10))
            ->get()
            ->map(fn (Attendance $attendance): array => [
                'date' => $attendance->attendance_date?->toDateString() ?? '',
                'checkInTime' => $this->formatTime($attendance->check_in),
                'checkOutTime' => $this->formatTime($attendance->check_out),
                'status' => $attendance->status,
                'employee' => [
                    'id' => $attendance->employee?->id ?? 0,
                    'name' => trim(($attendance->employee?->first_name ?? '').' '.($attendance->employee?->last_name ?? '')),
                    'department' => $attendance->employee?->department?->name,
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, array{role: string, totalUsers: int}>
     */
    private function getUsersByRole(): array
    {
        return Role::query()
            ->select(['id', 'name'])
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'role' => $role->name,
                'totalUsers' => $role->users_count,
            ])
            ->all();
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

        if ($employee === null) {
            throw new HttpException(403, 'Employee profile is not available.');
        }

        return $employee;
    }

    private function resolveRole(User $authenticatedUser): string
    {
        $role = $authenticatedUser->getRoleNames()->first();

        return in_array($role, Role::managedRoleNames(), true) ? $role : 'employee';
    }

    private function activeEmployeesQuery(): Builder
    {
        return Employee::query()->where('status', EmployeeStatus::Active->value);
    }

    private function getEmployeesOnLeaveTodayCount(): int
    {
        return LeaveRequest::query()
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->where('employees.status', EmployeeStatus::Active->value)
            ->where('leave_requests.status', 'hr_approved')
            ->whereDate('leave_requests.start_date', '<=', today())
            ->whereDate('leave_requests.end_date', '>=', today())
            ->distinct()
            ->count('leave_requests.employee_id');
    }

    private function todayAttendanceBaseQuery(): Builder
    {
        return Attendance::query()
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->where('employees.status', EmployeeStatus::Active->value)
            ->whereDate('attendances.attendance_date', today());
    }

    private function todayWorkStart(): Carbon
    {
        return today()->setTimeFromTimeString((string) config('dashboard.work_start_time', '08:00:00'));
    }

    private function isLateAttendance(Attendance $attendance): bool
    {
        if ($attendance->status === 'late') {
            return true;
        }

        if ($attendance->check_in === null || $attendance->attendance_date === null) {
            return false;
        }

        return $attendance->check_in->gt(
            Carbon::parse($attendance->attendance_date)->setTimeFromTimeString((string) config('dashboard.work_start_time', '08:00:00'))
        );
    }

    private function formatTime(?Carbon $timestamp): ?string
    {
        return $timestamp?->format('H:i:s');
    }
}
