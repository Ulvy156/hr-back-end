<?php

namespace App\Models;

use App\PermissionName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const MANAGED_ROLE_NAMES = [
        'admin',
        'employee',
        'hr',
        'hr_head',
        'hr_manager',
        'manager',
    ];

    protected string $guard_name = 'api';

    protected $fillable = [
        'name',
        'description',
        'guard_name',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $role): void {
            $permissions = self::defaultPermissionNamesFor($role->name);

            if ($permissions === []) {
                return;
            }

            $currentPermissions = $role->permissions()
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $expectedPermissions = collect($permissions)
                ->sort()
                ->values()
                ->all();

            if ($currentPermissions === $expectedPermissions) {
                return;
            }

            $role->syncPermissions($permissions);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('access_control')
            ->logOnly(['name', 'description', 'guard_name'])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    /**
     * @return array<int, string>
     */
    public static function managedRoleNames(): array
    {
        return self::MANAGED_ROLE_NAMES;
    }

    /**
     * @return array<int, string>
     */
    public static function defaultRoleNamesForPermission(string $permissionName): array
    {
        return collect(self::managedRoleNames())
            ->filter(fn (string $roleName): bool => in_array($permissionName, self::defaultPermissionNamesFor($roleName), true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function defaultPermissionNamesFor(string $roleName): array
    {
        return match ($roleName) {
            'admin' => self::adminPermissionNames(),
            'employee' => self::employeePermissionNames(),
            'hr', 'hr_head', 'hr_manager' => self::hrPermissionNames(),
            'manager' => self::managerPermissionNames(),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function employeePermissionNames(): array
    {
        return [
            PermissionName::AttendanceCorrectionRequest->value,
            PermissionName::AttendanceExport->value,
            PermissionName::AttendanceMissingRequest->value,
            PermissionName::AttendanceRecord->value,
            PermissionName::AttendanceSummarySelf->value,
            PermissionName::AttendanceView->value,
            PermissionName::AttendanceViewSelf->value,
            PermissionName::EmployeeView->value,
            PermissionName::EmployeeViewSelf->value,
            PermissionName::LeaveBalanceViewSelf->value,
            PermissionName::LeaveRequestCancelSelf->value,
            PermissionName::LeaveRequestCreate->value,
            PermissionName::LeaveRequestViewSelf->value,
            PermissionName::LeaveTypeView->value,
            PermissionName::LocationView->value,
            PermissionName::OvertimeRequestCancel->value,
            PermissionName::OvertimeRequestCreate->value,
            PermissionName::OvertimeRequestViewSelf->value,
            PermissionName::PayrollPayslipViewOwn->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function managerPermissionNames(): array
    {
        return self::uniquePermissionNames([
            ...self::employeePermissionNames(),
            PermissionName::LeaveApproveManager->value,
            PermissionName::LeaveRequestViewAssigned->value,
            PermissionName::OvertimeApproveManager->value,
            PermissionName::OvertimeRequestViewAssigned->value,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function hrPermissionNames(): array
    {
        return self::uniquePermissionNames([
            PermissionName::AttendanceCorrectionRequest->value,
            PermissionName::AttendanceExport->value,
            PermissionName::AttendanceExportAny->value,
            PermissionName::AttendanceManage->value,
            PermissionName::AttendanceMissingRequest->value,
            PermissionName::AttendanceRecord->value,
            PermissionName::AttendanceSummaryAny->value,
            PermissionName::AttendanceSummarySelf->value,
            PermissionName::AttendanceView->value,
            PermissionName::AttendanceViewAny->value,
            PermissionName::AttendanceViewSelf->value,
            PermissionName::EmployeeExport->value,
            PermissionName::EmployeeManage->value,
            PermissionName::EmployeeUserLinkView->value,
            PermissionName::EmployeeView->value,
            PermissionName::EmployeeViewAny->value,
            PermissionName::LeaveApproveHr->value,
            PermissionName::LeaveBalanceViewSelf->value,
            PermissionName::LeaveRequestCancelSelf->value,
            PermissionName::LeaveRequestCreate->value,
            PermissionName::LeaveRequestViewAny->value,
            PermissionName::LeaveRequestViewSelf->value,
            PermissionName::LeaveTypeManage->value,
            PermissionName::LeaveTypeView->value,
            PermissionName::HolidayManage->value,
            PermissionName::LocationView->value,
            PermissionName::OvertimeRequestCancel->value,
            PermissionName::OvertimeRequestCreate->value,
            PermissionName::OvertimeRequestViewAny->value,
            PermissionName::OvertimeRequestViewSelf->value,
            PermissionName::PayrollExport->value,
            PermissionName::PayrollPayslipViewAny->value,
            PermissionName::PayrollRunApprove->value,
            PermissionName::PayrollRunCancel->value,
            PermissionName::PayrollRunGenerate->value,
            PermissionName::PayrollRunMarkPaid->value,
            PermissionName::PayrollRunRegenerate->value,
            PermissionName::PayrollRunView->value,
            PermissionName::PayrollSalaryManage->value,
            PermissionName::PayrollSalaryView->value,
            PermissionName::PositionView->value,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function adminPermissionNames(): array
    {
        return self::uniquePermissionNames([
            PermissionName::AttendanceCorrectionRequest->value,
            PermissionName::AttendanceAuditView->value,
            PermissionName::AttendanceExport->value,
            PermissionName::AttendanceExportAny->value,
            PermissionName::AttendanceMissingRequest->value,
            PermissionName::AttendanceRecord->value,
            PermissionName::AttendanceSummaryAny->value,
            PermissionName::AttendanceSummarySelf->value,
            PermissionName::AttendanceView->value,
            PermissionName::AttendanceViewAny->value,
            PermissionName::AttendanceViewSelf->value,
            PermissionName::AuditLogExport->value,
            PermissionName::AuditLogView->value,
            PermissionName::EmployeeExport->value,
            PermissionName::EmployeeUserLinkView->value,
            PermissionName::EmployeeView->value,
            PermissionName::EmployeeViewAny->value,
            PermissionName::EmployeeViewSelf->value,
            PermissionName::LeaveBalanceViewSelf->value,
            PermissionName::LeaveRequestCancelSelf->value,
            PermissionName::LeaveRequestCreate->value,
            PermissionName::LeaveRequestViewAny->value,
            PermissionName::LeaveRequestViewAssigned->value,
            PermissionName::LeaveRequestViewSelf->value,
            PermissionName::LeaveTypeView->value,
            PermissionName::LocationView->value,
            PermissionName::OvertimeRequestViewAny->value,
            PermissionName::PermissionManage->value,
            PermissionName::PermissionView->value,
            PermissionName::PayrollExport->value,
            PermissionName::PayrollPayslipViewAny->value,
            PermissionName::PayrollRunView->value,
            PermissionName::PayrollSalaryView->value,
            PermissionName::PositionView->value,
            PermissionName::RoleAssign->value,
            PermissionName::RoleManage->value,
            PermissionName::RoleView->value,
            PermissionName::UserManage->value,
            PermissionName::UserPermissionAssign->value,
            PermissionName::UserRoleAssign->value,
            PermissionName::UserUpdate->value,
            PermissionName::UserView->value,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private static function uniquePermissionNames(array $permissions): array
    {
        return collect($permissions)
            ->unique()
            ->values()
            ->all();
    }
}
