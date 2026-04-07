# HR Management System

## Overview

This project is an **HR Management System** built to manage core HR operations in one place. It focuses on employee data, attendance, leave workflows, payroll-related processes, and access control.

The system is designed for multiple roles such as **Admin**, **HR**, and **Employee**, with different permissions and responsibilities across modules.

## Main Goal

The goal of this project is to replace manual HR work with a centralized system that is easier to manage, easier to audit, and safer for sensitive employee data.

## Core Modules

### 1. Employee Management

Handles employee records and organizational structure.

**Includes:**

* Employee profile management
* Department and position organization
* Employment status tracking
* Document and photo storage
* Manager assignment and reporting hierarchy

**Why it matters:**

* Creates a single source of truth for employee data
* Reduces duplicate records and manual work
* Makes reporting lines and supervision clear

### 2. Attendance Management

Tracks employee attendance and work time.

**Includes:**

* Check-in and check-out tracking
* Automatic work-hour calculation
* Late arrival and early leave detection
* Attendance history logs
* Monthly attendance summaries
* Attendance correction request flow
* Missing attendance request flow
* QR attendance scan support

**Why it matters:**

* Improves accuracy of attendance records
* Reduces disputes and manual calculation
* Helps HR and managers monitor punctuality and missing logs

### 3. Leave Management

Manages leave requests, leave balances, and approvals.

**Includes:**

* Online leave request submission
* Multi-level approval flow
* Automatic leave balance tracking
* Leave history records
* Manager and HR notifications
* Leave types based on Cambodia labor law
* Public holiday support

**Why it matters:**

* Makes leave approval faster and more transparent
* Prevents balance miscalculation
* Supports policy compliance

### 4. Payroll Management

Supports payroll-related processing.

**Includes:**

* Salary structure configuration
* Allowances and deductions
* Overtime and penalty calculation
* Payslip generation
* Payroll report export

**Why it matters:**

* Standardizes salary processing
* Improves transparency
* Supports reporting and audit needs

### 5. Role and Permission Management

Controls access to the system by role.

**Includes:**

* Role-based access control
* Permission assignment by module
* User role assignment
* Access restriction for sensitive data
* Audit trail for permission changes

**Why it matters:**

* Protects sensitive HR and payroll data
* Prevents unauthorized actions
* Improves accountability

## Roles

### Employee

Typical access includes:

* View own profile
* Check in / check out
* Scan QR for attendance
* View own attendance history
* Submit leave requests
* Submit correction or missing attendance requests
* Export own attendance data if permitted

### HR

Typical access includes:

* Full employee management
* Attendance monitoring and correction oversight
* Leave review and approval
* Public holiday and leave type management
* Audit log visibility
* Data export access

### Admin

Typical access includes:

* User and role management
* High-level system oversight
* Read-only or controlled access to some HR data depending on business rule
* Access to dashboards and reports

## Approval Flow

### Leave Approval

Current business direction:

* First approval by **Manager**
* Final approval by **HR**

This keeps manager-level operational review while making HR the final control point.

## Attendance Notes

Attendance is one of the key modules in this project.

### Current direction

* Attendance supports standard app-based check-in/check-out
* Attendance also supports QR-based scan flow
* Time display should use user-friendly 12-hour formatting, for example `1:00 PM` instead of `13:00`
* Missing attendance and correction request APIs should follow the latest Postman collection

### QR flow idea

* Employee or HR scans from mobile
* QR redirects to the system
* System automatically processes check-in or check-out based on current attendance state
* Admin does not use scan flow

## API Integration

The project uses a **Postman collection as the API contract/reference**.

### Important rule for implementation

When generating or updating frontend/backend features:

* Follow request/response formats from the Postman collection
* Do not guess field names when the collection already defines them
* Keep UI logic aligned with the available endpoints

## Frontend Direction

Based on current project direction, the frontend includes:

* Dashboard by role
* Auth flow using `/auth/me`
* Current user state stored centrally
* Cookie-based token handling for access token
* Route protection based on auth and role/permission
* Admin and HR management pages
* Audit log detail modal/page
* Export actions from filtered views

## Backend Direction

Based on current project direction, the backend should support:

* Employee management
* Attendance workflows
* Leave workflows with Cambodia labor-law-aligned leave types
* Public holiday seeding/import
* Audit logs for key actions
* Export support for attendance and audit logs
* Approval flows for leave and attendance-related requests

## Security Expectations

Security matters in this project.

### Key expectations

* Protect routes and sensitive modules by role/permission
* Restrict payroll and personal data access
* Track critical actions with audit logs
* Reuse shared logic where possible instead of duplicating risky behavior
* Keep permission checks both in UI and backend

## Suggested Development Rules for Codex

When working on this project, Codex should follow these rules:

1. **Use the Postman collection as source of truth** for API shape.
2. **Reuse existing logic first** before creating new utilities.
3. **Keep shared logic centralized**, especially for auth, permissions, date/time formatting, and exports.
4. **Avoid breaking existing flows** when adding new features.
5. **Respect role-based access** in both UI and backend.
6. **Prefer maintainable structure** over quick hacks.
7. **Keep laptop-first UI acceptable**, unless responsive behavior is specifically requested later.
8. **Update related API docs/collections** when backend endpoints change.

## Suggested Folder Understanding for Codex

Codex should understand the project in these layers:

* **Auth layer**: login, token handling, current user, route protection
* **Employee layer**: employee data, profile, org structure, manager relationship
* **Attendance layer**: check-in/out, logs, correction, missing requests, exports, QR flow
* **Leave layer**: leave types, requests, balances, approval flow, public holidays
* **Admin/HR layer**: dashboards, management pages, audit logs, permissions

## What Success Looks Like

A good implementation in this project should:

* Match the Postman collection
* Respect roles and permissions
* Keep business logic reusable
* Be easy for HR and Admin to operate
* Be simple enough for employees to use without confusion
* Keep data auditable and secure

## Summary

This HR Management System is meant to be a practical, role-based internal system that handles employee data, attendance, leave, payroll support, and permissions in a clean and maintainable way.

When contributing to this project, always optimize for:

* correctness
* reuse
* security
* clarity
* alignment with the existing API contract
