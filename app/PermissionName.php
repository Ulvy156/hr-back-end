<?php

namespace App;

enum PermissionName: string
{
    case AuditLogExport = 'audit-log.export';
    case AuditLogView = 'audit-log.view';
    case AttendanceAuditView = 'attendance.audit.view';
    case AttendanceCorrectionRequest = 'attendance.correction.request';
    case AttendanceExport = 'attendance.export';
    case AttendanceExportAny = 'attendance.export.any';
    case AttendanceManage = 'attendance.manage';
    case AttendanceMissingRequest = 'attendance.missing.request';
    case AttendanceRecord = 'attendance.record';
    case AttendanceSummaryAny = 'attendance.summary.any';
    case AttendanceSummarySelf = 'attendance.summary.self';
    case AttendanceView = 'attendance.view';
    case AttendanceViewAny = 'attendance.view.any';
    case AttendanceViewSelf = 'attendance.view.self';
    case EmployeeExport = 'employee.export';
    case EmployeeManage = 'employee.manage';
    case EmployeeUserLinkView = 'employee.user-link.view';
    case EmployeeView = 'employee.view';
    case EmployeeViewAny = 'employee.view.any';
    case EmployeeViewSelf = 'employee.view.self';
    case LeaveApproveHr = 'leave.approve.hr';
    case LeaveBalanceViewSelf = 'leave.balance.view.self';
    case LeaveApproveManager = 'leave.approve.manager';
    case LeaveRequestCancelSelf = 'leave.request.cancel.self';
    case LeaveRequestCreate = 'leave.request.create';
    case LeaveRequestViewAny = 'leave.request.view.any';
    case LeaveRequestViewAssigned = 'leave.request.view.assigned';
    case LeaveRequestViewSelf = 'leave.request.view.self';
    case LeaveTypeView = 'leave.type.view';
    case LocationView = 'location.view';
    case PermissionManage = 'permission.manage';
    case PermissionView = 'permission.view';
    case PayrollRunApprove = 'payroll.run.approve';
    case PayrollRunCancel = 'payroll.run.cancel';
    case PayrollExport = 'payroll.export';
    case PayrollRunGenerate = 'payroll.run.generate';
    case PayrollRunRegenerate = 'payroll.run.regenerate';
    case PayrollRunMarkPaid = 'payroll.run.mark-paid';
    case PayrollRunView = 'payroll.run.view';
    case PayrollPayslipViewAny = 'payroll.payslip.view.any';
    case PayrollPayslipViewOwn = 'payroll.payslip.view-own';
    case PayrollSalaryManage = 'payroll.salary.manage';
    case PayrollSalaryView = 'payroll.salary.view';
    case PositionView = 'position.view';
    case RoleAssign = 'role.assign';
    case RoleManage = 'role.manage';
    case RoleView = 'role.view';
    case UserPermissionAssign = 'user.permission.assign';
    case UserManage = 'user.manage';
    case UserRoleAssign = 'user.role.assign';
    case UserUpdate = 'user.update';
    case UserView = 'user.view';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            self::AuditLogExport->value => 'Allow exporting audit log records for compliance, investigations, or reporting.',
            self::AuditLogView->value => 'Allow viewing audit log entries and change history across the HR system.',
            self::AttendanceAuditView->value => 'Allow viewing attendance audit trails, including who corrected or reviewed attendance changes.',
            self::AttendanceCorrectionRequest->value => 'Allow employees to request a correction for their own attendance records.',
            self::AttendanceExport->value => 'Allow exporting attendance reports for the employee scope already available to the user.',
            self::AttendanceExportAny->value => 'Allow exporting attendance reports for any employee or broader organization scopes.',
            self::AttendanceManage->value => 'Allow HR or administrators to create, correct, recover, and review attendance records and correction workflows.',
            self::AttendanceMissingRequest->value => 'Allow employees to submit a missing attendance request for days without a recorded check-in or check-out.',
            self::AttendanceRecord->value => 'Allow recording personal attendance events, including scan, check-in, and check-out actions.',
            self::AttendanceSummaryAny->value => 'Allow viewing attendance summary dashboards for teams or the full organization.',
            self::AttendanceSummarySelf->value => 'Allow viewing personal attendance summary metrics.',
            self::AttendanceView->value => 'Allow opening attendance data screens that are available to the user.',
            self::AttendanceViewAny->value => 'Allow viewing attendance records for any employee.',
            self::AttendanceViewSelf->value => 'Allow viewing the authenticated user\'s own attendance history and today status.',
            self::EmployeeExport->value => 'Allow exporting employee records and employee master data.',
            self::EmployeeManage->value => 'Allow creating, updating, restoring, activating, deactivating, terminating, and deleting employee records and related profile data.',
            self::EmployeeUserLinkView->value => 'Allow viewing user accounts that can be linked to employee profiles.',
            self::EmployeeView->value => 'Allow opening employee information screens that are available to the user.',
            self::EmployeeViewAny->value => 'Allow viewing any employee profile.',
            self::EmployeeViewSelf->value => 'Allow viewing the authenticated employee\'s own profile.',
            self::LeaveApproveHr->value => 'Allow HR to perform the final leave approval step.',
            self::LeaveApproveManager->value => 'Allow a direct manager or configured leave approver to approve leave at the first approval step.',
            self::LeaveBalanceViewSelf->value => 'Allow viewing the authenticated user\'s own leave balances.',
            self::LeaveRequestCancelSelf->value => 'Allow cancelling the authenticated user\'s own leave requests when cancellation is still permitted.',
            self::LeaveRequestCreate->value => 'Allow creating a leave request for the authenticated user.',
            self::LeaveRequestViewAny->value => 'Allow viewing all leave requests across the organization.',
            self::LeaveRequestViewAssigned->value => 'Allow viewing leave requests assigned to the current approver for action or review.',
            self::LeaveRequestViewSelf->value => 'Allow viewing the authenticated user\'s own leave requests.',
            self::LeaveTypeView->value => 'Allow viewing leave types, public holidays, and leave policy reference data.',
            self::LocationView->value => 'Allow viewing employee location reference data such as provinces, districts, communes, and villages.',
            self::PermissionManage->value => 'Allow managing the permission catalog or permission records in access-control administration.',
            self::PermissionView->value => 'Allow viewing the permission catalog for assignment and UI authorization.',
            self::PayrollRunApprove->value => 'Allow approving payroll runs.',
            self::PayrollRunCancel->value => 'Allow cancelling payroll runs.',
            self::PayrollExport->value => 'Allow exporting payroll runs to downloadable Excel reports.',
            self::PayrollRunGenerate->value => 'Allow generating monthly payroll runs.',
            self::PayrollRunRegenerate->value => 'Allow regenerating existing payroll runs.',
            self::PayrollRunMarkPaid->value => 'Allow marking payroll runs as paid.',
            self::PayrollRunView->value => 'Allow viewing payroll run summaries and details.',
            self::PayrollPayslipViewAny->value => 'Allow viewing payroll items or payslips for any employee.',
            self::PayrollPayslipViewOwn->value => 'Allow viewing the authenticated employee\'s own payslips.',
            self::PayrollSalaryManage->value => 'Allow creating and updating payroll salary setup records.',
            self::PayrollSalaryView->value => 'Allow viewing payroll salary setup records.',
            self::PositionView->value => 'Allow viewing employee position reference data.',
            self::RoleAssign->value => 'Allow assigning managed role groups through access-control workflows.',
            self::RoleManage->value => 'Allow managing role definitions used by the HR system.',
            self::RoleView->value => 'Allow viewing managed role groups and their bundled permissions.',
            self::UserPermissionAssign->value => 'Allow assigning or removing direct permissions on individual user accounts.',
            self::UserManage->value => 'Allow creating, updating, deleting, and resetting passwords for system users.',
            self::UserRoleAssign->value => 'Allow assigning or replacing user roles.',
            self::UserUpdate->value => 'Allow updating user account details.',
            self::UserView->value => 'Allow viewing system user accounts and access summaries.',
        ];
    }

    public function description(): string
    {
        return self::descriptions()[$this->value];
    }

    /**
     * @return array<int, string>
     */
    public static function accessManagementValues(): array
    {
        return [
            self::RoleView->value,
            self::RoleManage->value,
            self::PermissionView->value,
            self::PermissionManage->value,
            self::UserView->value,
            self::UserUpdate->value,
            self::UserRoleAssign->value,
            self::UserPermissionAssign->value,
        ];
    }
}
