<?php

namespace App;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;

class PermissionCatalog
{
    /**
     * @var array<string, string>
     */
    private const MODULE_LABELS = [
        'employee' => 'Employee',
        'attendance' => 'Attendance',
        'leave' => 'Leave',
        'overtime' => 'Overtime',
        'payroll' => 'Payroll',
        'user_role_management' => 'User/Role Management',
        'audit_log' => 'Audit Log',
        'custom' => 'Custom',
    ];

    public static function namingPattern(): string
    {
        return 'module.action[.scope]';
    }

    /**
     * @return array<int, array{id: int, name: string, description: string, module: string, module_label: string, recommended_roles: array<int, string>, system_defined: bool}>
     */
    public static function all(): array
    {
        return self::fromPermissions(
            Permission::query()
                ->select(['id', 'name', 'description'])
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @return array<int, array{id: int, name: string, description: string, module: string, module_label: string, recommended_roles: array<int, string>, system_defined: bool}>
     */
    public static function fromPermissions(Collection $permissions): array
    {
        return $permissions
            ->map(function (Permission $permission): array {
                $systemPermission = PermissionName::tryFrom($permission->name);
                $module = self::moduleFor($systemPermission, $permission->name);

                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'description' => $systemPermission?->description() ?? (string) $permission->description,
                    'module' => $module,
                    'module_label' => self::MODULE_LABELS[$module],
                    'recommended_roles' => $systemPermission instanceof PermissionName
                        ? Role::defaultRoleNamesForPermission($systemPermission->value)
                        : [],
                    'system_defined' => $systemPermission instanceof PermissionName,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return array{naming_pattern: string, modules: array<int, array{key: string, label: string, permissions: array<int, array{id: int, name: string, description: string, module: string, module_label: string, recommended_roles: array<int, string>, system_defined: bool}>}>}
     */
    public static function payload(): array
    {
        $definitions = collect(self::all());
        $modules = collect(self::MODULE_LABELS)
            ->reject(fn (string $_label, string $key): bool => $key === 'custom')
            ->map(function (string $label, string $key) use ($definitions): array {
                return [
                    'key' => $key,
                    'label' => $label,
                    'permissions' => $definitions
                        ->where('module', $key)
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        if ($definitions->contains(fn (array $definition): bool => $definition['module'] === 'custom')) {
            $modules->push([
                'key' => 'custom',
                'label' => self::MODULE_LABELS['custom'],
                'permissions' => $definitions
                    ->where('module', 'custom')
                    ->values()
                    ->all(),
            ]);
        }

        return [
            'naming_pattern' => self::namingPattern(),
            'modules' => $modules->all(),
        ];
    }

    private static function moduleFor(?PermissionName $permission, string $permissionName): string
    {
        if ($permission instanceof PermissionName) {
            return match ($permission) {
                PermissionName::EmployeeExport,
                PermissionName::EmployeeManage,
                PermissionName::EmployeeUserLinkView,
                PermissionName::EmployeeView,
                PermissionName::EmployeeViewAny,
                PermissionName::EmployeeViewSelf,
                PermissionName::LocationView,
                PermissionName::PositionView => 'employee',
                PermissionName::AttendanceAuditView,
                PermissionName::AttendanceCorrectionRequest,
                PermissionName::AttendanceExport,
                PermissionName::AttendanceExportAny,
                PermissionName::AttendanceManage,
                PermissionName::AttendanceMissingRequest,
                PermissionName::AttendanceRecord,
                PermissionName::AttendanceSummaryAny,
                PermissionName::AttendanceSummarySelf,
                PermissionName::AttendanceView,
                PermissionName::AttendanceViewAny,
                PermissionName::AttendanceViewSelf => 'attendance',
                PermissionName::LeaveApproveHr,
                PermissionName::LeaveApproveManager,
                PermissionName::LeaveBalanceViewSelf,
                PermissionName::LeaveRequestCancelSelf,
                PermissionName::LeaveRequestCreate,
                PermissionName::LeaveRequestViewAny,
                PermissionName::LeaveRequestViewAssigned,
                PermissionName::LeaveRequestViewSelf,
                PermissionName::LeaveTypeManage,
                PermissionName::HolidayManage,
                PermissionName::LeaveTypeView => 'leave',
                PermissionName::OvertimeApproveManager,
                PermissionName::OvertimeRequestCancel,
                PermissionName::OvertimeRequestCreate,
                PermissionName::OvertimeRequestViewAny,
                PermissionName::OvertimeRequestViewAssigned,
                PermissionName::OvertimeRequestViewSelf => 'overtime',
                PermissionName::PayrollRunApprove,
                PermissionName::PayrollRunCancel,
                PermissionName::PayrollExport,
                PermissionName::PayrollRunGenerate,
                PermissionName::PayrollRunRegenerate,
                PermissionName::PayrollRunMarkPaid,
                PermissionName::PayrollRunView,
                PermissionName::PayrollPayslipViewAny,
                PermissionName::PayrollPayslipViewOwn,
                PermissionName::PayrollSalaryManage,
                PermissionName::PayrollSalaryView => 'payroll',
                PermissionName::PermissionManage,
                PermissionName::PermissionView,
                PermissionName::RoleAssign,
                PermissionName::RoleManage,
                PermissionName::RoleView,
                PermissionName::UserManage,
                PermissionName::UserPermissionAssign,
                PermissionName::UserRoleAssign,
                PermissionName::UserUpdate,
                PermissionName::UserView => 'user_role_management',
                PermissionName::AuditLogExport,
                PermissionName::AuditLogView => 'audit_log',
            };
        }

        if (str_starts_with($permissionName, 'payroll.')) {
            return 'payroll';
        }

        if (str_starts_with($permissionName, 'overtime.')) {
            return 'overtime';
        }

        return 'custom';
    }
}
