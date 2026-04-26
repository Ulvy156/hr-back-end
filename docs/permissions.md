# Permission Catalog

This document is the frontend-facing reference for all permissions exposed by the HR system.

## Naming Pattern

Standard pattern: `module.action[.scope]`

Examples:

- `employee.view.self`
- `leave.request.create`
- `attendance.view.any`

Legacy names retained for backward compatibility:

- `audit-log.view`
- `audit-log.export`
- `location.view`
- `position.view`
- `user.role.assign`
- `user.permission.assign`

Frontend must always use the backend-provided permission names and must not guess names from role labels.

## `/auth/me`

`GET /api/auth/me` returns the authenticated user together with assigned roles, effective permissions, and the grouped permission catalog.

```json
{
  "id": 1,
  "name": "Dara Lim",
  "email": "dara.lim@example.com",
  "roles": [
    {
      "id": 3,
      "name": "employee",
      "description": "Employee"
    }
  ],
  "permissions": [
    "attendance.record",
    "employee.view.self",
    "leave.request.create"
  ],
  "permission_catalog": {
    "naming_pattern": "module.action[.scope]",
    "modules": [
      {
        "key": "leave",
        "label": "Leave",
        "permissions": [
          {
            "id": 17,
            "name": "leave.approve.hr",
            "description": "Allow HR to perform the final leave approval step.",
            "module": "leave",
            "module_label": "Leave",
            "recommended_roles": ["admin", "hr"],
            "system_defined": true
          }
        ]
      }
    ]
  }
}
```

## Frontend Helper

```ts
type AuthMeResponse = {
  permissions: string[];
};

export function createPermissionHelpers(auth: AuthMeResponse) {
  const grantedPermissions = new Set(auth.permissions);

  const can = (permission: string): boolean => grantedPermissions.has(permission);
  const canAny = (permissions: string[]): boolean => permissions.some(can);
  const canAll = (permissions: string[]): boolean => permissions.every(can);

  return { can, canAny, canAll };
}
```

## Leave Approval Example

```tsx
const { can } = createPermissionHelpers(authMe);

export function LeaveApprovalActions() {
  return (
    <>
      {can('leave.approve.manager') && (
        <button type="button">Manager Approve</button>
      )}

      {can('leave.approve.hr') && (
        <button type="button">HR Final Approve</button>
      )}
    </>
  );
}
```

## Permissions By Module

### Employee

| Permission | Description | Recommended roles |
| --- | --- | --- |
| `employee.export` | Allow exporting employee records and employee master data. | Admin, HR |
| `employee.manage` | Allow creating, updating, restoring, activating, deactivating, terminating, and deleting employee records and related profile data. | Admin, HR |
| `employee.user-link.view` | Allow viewing user accounts that can be linked to employee profiles. | Admin, HR |
| `employee.view` | Allow opening employee information screens that are available to the user. | Admin, HR, Employee |
| `employee.view.any` | Allow viewing any employee profile. | Admin, HR |
| `employee.view.self` | Allow viewing the authenticated employee's own profile. | Admin, HR, Employee |
| `location.view` | Allow viewing employee location reference data such as provinces, districts, communes, and villages. | Admin, Employee, HR |
| `position.view` | Allow viewing employee position reference data. | HR |

### Attendance

| Permission | Description | Recommended roles |
| --- | --- | --- |
| `attendance.audit.view` | Allow viewing attendance audit trails, including who corrected or reviewed attendance changes. | Admin |
| `attendance.correction.request` | Allow employees to request a correction for their own attendance records. | Admin, Employee, HR |
| `attendance.export` | Allow exporting attendance reports for the employee scope already available to the user. | Admin, Employee, HR |
| `attendance.export.any` | Allow exporting attendance reports for any employee or broader organization scopes. | Admin, HR |
| `attendance.manage` | Allow HR or administrators to create, correct, recover, and review attendance records and correction workflows. | Admin, HR |
| `attendance.missing.request` | Allow employees to submit a missing attendance request for days without a recorded check-in or check-out. | Admin, Employee, HR |
| `attendance.record` | Allow recording personal attendance events, including scan, check-in, and check-out actions. | Admin, Employee, HR |
| `attendance.summary.any` | Allow viewing attendance summary dashboards for teams or the full organization. | Admin, HR |
| `attendance.summary.self` | Allow viewing personal attendance summary metrics. | Admin, Employee, HR |
| `attendance.view` | Allow opening attendance data screens that are available to the user. | Admin, Employee, HR |
| `attendance.view.any` | Allow viewing attendance records for any employee. | Admin, HR |
| `attendance.view.self` | Allow viewing the authenticated user's own attendance history and today status. | Admin, Employee, HR |

### Leave

| Permission | Description | Recommended roles |
| --- | --- | --- |
| `leave.approve.hr` | Allow HR to perform the final leave approval step. | Admin, HR |
| `leave.approve.manager` | Allow a direct manager or configured leave approver to approve leave at the first approval step. | Manager |
| `leave.balance.view.self` | Allow viewing the authenticated user's own leave balances. | Admin, Employee, HR, Manager |
| `leave.request.cancel.self` | Allow cancelling the authenticated user's own leave requests when cancellation is still permitted. | Admin, Employee, HR, Manager |
| `leave.request.create` | Allow creating a leave request for the authenticated user. | Admin, Employee, HR, Manager |
| `leave.request.view.any` | Allow viewing all leave requests across the organization. | Admin, HR |
| `leave.request.view.assigned` | Allow viewing leave requests assigned to the current approver for action or review. | Admin, Manager |
| `leave.request.view.self` | Allow viewing the authenticated user's own leave requests. | Admin, Employee, HR, Manager |
| `leave.type.view` | Allow viewing leave types, public holidays, and leave policy reference data. | Admin, Employee, HR, Manager |

### Payroll

| Permission | Description | Recommended roles |
| --- | --- | --- |
| `payroll.export` | Allow exporting payroll runs to downloadable Excel reports. | Admin, HR |
| `payroll.payslip.view-own` | Allow viewing the authenticated employee's own payslips. | Employee |
| `payroll.payslip.view.any` | Allow viewing payroll items or payslips for any employee. | HR |
| `payroll.run.approve` | Allow approving payroll runs. | HR |
| `payroll.run.cancel` | Allow cancelling payroll runs. | HR |
| `payroll.run.generate` | Allow generating monthly payroll runs. | HR |
| `payroll.run.mark-paid` | Allow marking payroll runs as paid. | HR |
| `payroll.run.view` | Allow viewing payroll run summaries and details. | Admin, HR |
| `payroll.salary.manage` | Allow creating and updating payroll salary setup records. | HR |
| `payroll.salary.view` | Allow viewing payroll salary setup records. | HR |

### User/Role Management

| Permission | Description | Recommended roles |
| --- | --- | --- |
| `permission.manage` | Allow managing the permission catalog or permission records in access-control administration. | Admin |
| `permission.view` | Allow viewing the permission catalog for assignment and UI authorization. | Admin |
| `role.assign` | Allow assigning managed role groups through access-control workflows. | Admin |
| `role.manage` | Allow managing role definitions used by the HR system. | Admin |
| `role.view` | Allow viewing managed role groups and their bundled permissions. | Admin |
| `user.manage` | Allow creating, updating, deleting, and resetting passwords for system users. | Admin |
| `user.permission.assign` | Allow assigning or removing direct permissions on individual user accounts. | Admin |
| `user.role.assign` | Allow assigning or replacing user roles. | Admin |
| `user.update` | Allow updating user account details. | Admin |
| `user.view` | Allow viewing system user accounts and access summaries. | Admin |

### Audit Log

| Permission | Description | Recommended roles |
| --- | --- | --- |
| `audit-log.export` | Allow exporting audit log records for compliance, investigations, or reporting. | Admin |
| `audit-log.view` | Allow viewing audit log entries and change history across the HR system. | Admin |
