# Payroll API

This document describes the implemented payroll backend contract and the corresponding Postman collection at [payroll-api.postman_collection.json](D:/AEU/Thesis/Project/hr-back-end/payroll-api.postman_collection.json).

## Payroll Rules

- No allowance.
- No commission.
- No late salary cut.
- Overtime adds salary.
- Only approved unpaid leave reduces salary.
- Salary is prorated when an employee joins or resigns mid-month.
- Payroll generation is all-or-nothing. If any included employee has no valid salary, the whole request fails and no payroll run or payroll items are created.
- `employee_salaries` is the payroll source of truth.
- `employee_positions.base_salary` is compatibility-only fallback when no salary history exists yet.

## Common Error Examples

Permission error example:

```json
{
  "message": "Forbidden."
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "Validation message."
    ]
  }
}
```

## Salary Endpoints

### GET `/api/payroll/salaries`

- Method: `GET`
- Required permission: `payroll.salary.view`
- Query params:
  - `employee_id` nullable integer
  - `effective_on` nullable date
  - `per_page` nullable integer `1..100`

Success response example:

```json
{
  "data": [
    {
      "id": 1,
      "employee_id": 10,
      "employee": {
        "id": 10,
        "employee_code": "EMP001",
        "full_name": "Dara Lim"
      },
      "amount": "725.50",
      "effective_date": "2026-04-01",
      "end_date": null,
      "is_current": true,
      "created_at": "2026-04-26T00:10:00+07:00",
      "updated_at": "2026-04-26T00:10:00+07:00"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/payroll/salaries?page=1",
    "last": "http://localhost:8000/api/payroll/salaries?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://localhost:8000/api/payroll/salaries",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "effective_on": [
      "The effective on is not a valid date."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### POST `/api/payroll/salaries`

- Method: `POST`
- Required permission: `payroll.salary.manage`
- Request body:
  - `employee_id` required integer
  - `amount` required numeric, must be greater than `0`
  - `effective_date` required date
  - `end_date` nullable date, must be on or after `effective_date`

Success response example:

```json
{
  "id": 1,
  "employee_id": 10,
  "employee": {
    "id": 10,
    "employee_code": "EMP001",
    "full_name": "Dara Lim"
  },
  "amount": "725.50",
  "effective_date": "2026-04-01",
  "end_date": null,
  "is_current": true,
  "created_at": "2026-04-26T00:10:00+07:00",
  "updated_at": "2026-04-26T00:10:00+07:00"
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "effective_date": [
      "The salary period overlaps an existing salary record for this employee."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### PATCH `/api/payroll/salaries/{employeeSalary}`

- Method: `PATCH`
- Required permission: `payroll.salary.manage`
- Request body:
  - `amount` optional numeric, must be greater than `0`
  - `effective_date` optional date
  - `end_date` optional nullable date, must be on or after `effective_date`
  - `employee_id` is prohibited

Success response example:

```json
{
  "id": 1,
  "employee_id": 10,
  "employee": {
    "id": 10,
    "employee_code": "EMP001",
    "full_name": "Dara Lim"
  },
  "amount": "680.00",
  "effective_date": "2026-04-01",
  "end_date": null,
  "is_current": true,
  "created_at": "2026-04-26T00:10:00+07:00",
  "updated_at": "2026-04-26T00:20:00+07:00"
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "amount": [
      "The amount field must be greater than 0."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

## Payroll Run Endpoints

### POST `/api/payroll/runs`

- Method: `POST`
- Required permission: `payroll.run.generate`
- Request body:
  - `month` required `YYYY-MM`

Success response example:

```json
{
  "id": 1,
  "payroll_month": "2026-04-01",
  "status": "draft",
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "employee_count": 1,
  "total_base_salary": "2100.00",
  "total_prorated_base_salary": "2100.00",
  "total_overtime_pay": "0.00",
  "total_unpaid_leave_deduction": "0.00",
  "total_net_salary": "2100.00",
  "created_at": "2026-04-26T00:30:00+07:00",
  "updated_at": "2026-04-26T00:30:00+07:00"
}
```

Validation error example:

Duplicate month:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "month": [
      "A payroll run already exists for the selected month."
    ]
  }
}
```

All-or-nothing missing salary failure:

```json
{
  "message": "Payroll generation failed because some employees have no valid salary.",
  "errors": [
    {
      "employee_id": 11,
      "employee_code": "EMP002",
      "employee_name": "Sok Chan",
      "reason": "No valid salary found for selected month."
    }
  ]
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### GET `/api/payroll/runs`

- Method: `GET`
- Required permission: `payroll.run.view`
- Query params:
  - `month` nullable `YYYY-MM`
  - `status` nullable `draft|approved|paid|cancelled`
  - `per_page` nullable integer `1..100`

Success response example:

```json
{
  "data": [
    {
      "id": 1,
      "payroll_month": "2026-04-01",
      "status": "draft",
      "company_working_days": 21,
      "monthly_working_hours": 168,
      "employee_count": 2,
      "total_base_salary": "4200.00",
      "total_prorated_base_salary": "4100.00",
      "total_overtime_pay": "120.00",
      "total_unpaid_leave_deduction": "50.00",
      "total_net_salary": "4170.00",
      "created_at": "2026-04-26T00:40:00+07:00",
      "updated_at": "2026-04-26T00:40:00+07:00"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/payroll/runs?page=1",
    "last": "http://localhost:8000/api/payroll/runs?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://localhost:8000/api/payroll/runs",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "The selected status is invalid."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### GET `/api/payroll/runs/{payrollRun}`

- Method: `GET`
- Required permission: `payroll.run.view`
- Query params: none

Success response example:

```json
{
  "id": 1,
  "payroll_month": "2026-04-01",
  "status": "approved",
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "employee_count": 1,
  "total_base_salary": "2100.00",
  "total_prorated_base_salary": "2000.00",
  "total_overtime_pay": "60.00",
  "total_unpaid_leave_deduction": "100.00",
  "total_net_salary": "1960.00",
  "items": [
    {
      "id": 1,
      "employee_id": 5,
      "employee_code": "EMP001",
      "employee_name": "Dara Lim",
      "salary_source": "employee_salaries",
      "base_salary": "2100.00",
      "prorated_base_salary": "2000.00",
      "hourly_rate": "12.5000",
      "daily_rate": "100.0000",
      "eligible_working_days": 20,
      "company_working_days": 21,
      "monthly_working_hours": 168,
      "overtime_normal_hours": "2.00",
      "overtime_weekend_hours": "1.00",
      "overtime_holiday_hours": "0.50",
      "overtime_pay": "60.00",
      "unpaid_leave_units": "1.00",
      "unpaid_leave_deduction": "100.00",
      "raw_net_salary": "1960.00",
      "net_salary": "1960.00",
      "created_at": "2026-04-26T00:40:00+07:00",
      "updated_at": "2026-04-26T00:40:00+07:00"
    }
  ],
  "created_at": "2026-04-26T00:40:00+07:00",
  "updated_at": "2026-04-26T00:40:00+07:00"
}
```

Validation error example:

No request-body or query validation applies on this endpoint. An invalid or missing `{payrollRun}` path value returns `404 Not Found`.

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### PATCH `/api/payroll/runs/{payrollRun}/approve`

- Method: `PATCH`
- Required permission: `payroll.run.approve`
- Request body: none

Success response example:

```json
{
  "id": 1,
  "payroll_month": "2026-04-01",
  "status": "approved",
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "employee_count": 1,
  "total_base_salary": "2100.00",
  "total_prorated_base_salary": "2100.00",
  "total_overtime_pay": "0.00",
  "total_unpaid_leave_deduction": "0.00",
  "total_net_salary": "2100.00",
  "created_at": "2026-04-26T00:45:00+07:00",
  "updated_at": "2026-04-26T00:50:00+07:00"
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "Only draft payroll runs can be approved."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### PATCH `/api/payroll/runs/{payrollRun}/mark-paid`

- Method: `PATCH`
- Required permission: `payroll.run.mark-paid`
- Request body: none

Success response example:

```json
{
  "id": 1,
  "payroll_month": "2026-04-01",
  "status": "paid",
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "employee_count": 1,
  "total_base_salary": "2100.00",
  "total_prorated_base_salary": "2100.00",
  "total_overtime_pay": "0.00",
  "total_unpaid_leave_deduction": "0.00",
  "total_net_salary": "2100.00",
  "created_at": "2026-04-26T00:45:00+07:00",
  "updated_at": "2026-04-26T00:55:00+07:00"
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "Only approved payroll runs can be marked as paid."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### PATCH `/api/payroll/runs/{payrollRun}/cancel`

- Method: `PATCH`
- Required permission: `payroll.run.cancel`
- Request body: none

Success response example:

```json
{
  "id": 1,
  "payroll_month": "2026-04-01",
  "status": "cancelled",
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "employee_count": 1,
  "total_base_salary": "2100.00",
  "total_prorated_base_salary": "2100.00",
  "total_overtime_pay": "0.00",
  "total_unpaid_leave_deduction": "0.00",
  "total_net_salary": "2100.00",
  "created_at": "2026-04-26T00:45:00+07:00",
  "updated_at": "2026-04-26T00:56:00+07:00"
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "Only draft or approved payroll runs can be cancelled."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### PATCH `/api/payroll/runs/{payrollRun}/regenerate`

- Method: `PATCH`
- Required permission: `payroll.run.generate`
- Request body: none
- Behavior:
  - allowed only when current status is `draft`
  - deletes existing payroll items
  - recomputes the month from current payroll source data
  - still enforces all-or-nothing salary validation before rebuild

Success response example:

```json
{
  "id": 1,
  "payroll_month": "2026-04-01",
  "status": "draft",
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "employee_count": 1,
  "total_base_salary": "2100.00",
  "total_prorated_base_salary": "2100.00",
  "total_overtime_pay": "0.00",
  "total_unpaid_leave_deduction": "0.00",
  "total_net_salary": "2100.00",
  "created_at": "2026-04-26T00:45:00+07:00",
  "updated_at": "2026-04-26T01:00:00+07:00"
}
```

Validation error example:

Invalid status transition:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "Only draft payroll runs can be regenerated."
    ]
  }
}
```

Blocking salary issue:

```json
{
  "message": "Payroll generation failed because some employees have no valid salary.",
  "errors": [
    {
      "employee_id": 11,
      "employee_code": "EMP002",
      "employee_name": "Sok Chan",
      "reason": "No valid salary found for selected month."
    }
  ]
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### GET `/api/payroll/runs/{payrollRun}/export/excel`

- Method: `GET`
- Required permission: `payroll.export`
- Query params: none
- Success response: binary Excel download built from stored `payroll_run` and `payroll_items` snapshots only

Success response example:

```http
HTTP/1.1 200 OK
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename=payroll-run-2026-04.xlsx
```

Validation error example:

No request-body or query validation applies on this endpoint. An invalid or missing `{payrollRun}` path value returns `404 Not Found`.

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

## Payslip Endpoints

### GET `/api/payroll/me/payslips`

- Method: `GET`
- Required permission: `payroll.payslip.view-own`
- Access rule: always scoped to the authenticated user’s linked `employee_id`
- Query params:
  - `month` nullable `YYYY-MM`
  - `status` nullable `draft|approved|paid|cancelled`
  - `per_page` nullable integer `1..100`

Success response example:

```json
{
  "data": [
    {
      "id": 1,
      "payroll_run_id": 3,
      "payroll_month": "2026-04-01",
      "payroll_status": "paid",
      "salary_source": "employee_salaries",
      "base_salary": "2100.00",
      "prorated_base_salary": "2100.00",
      "hourly_rate": "12.5000",
      "daily_rate": "100.0000",
      "eligible_working_days": 21,
      "company_working_days": 21,
      "monthly_working_hours": 168,
      "overtime_normal_hours": "2.00",
      "overtime_weekend_hours": "0.00",
      "overtime_holiday_hours": "0.00",
      "overtime_pay": "30.00",
      "unpaid_leave_units": "0.50",
      "unpaid_leave_deduction": "50.00",
      "raw_net_salary": "2080.00",
      "net_salary": "2080.00",
      "created_at": "2026-04-26T01:10:00+07:00",
      "updated_at": "2026-04-26T01:10:00+07:00"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/payroll/me/payslips?page=1",
    "last": "http://localhost:8000/api/payroll/me/payslips?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://localhost:8000/api/payroll/me/payslips",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

Validation error example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "The selected status is invalid."
    ]
  }
}
```

Auth or permission error example:

```json
{
  "message": "Forbidden."
}
```

### GET `/api/payroll/me/payslips/{payrollItem}`

- Method: `GET`
- Required permission: `payroll.payslip.view-own`
- Access rule: the authenticated employee can view only their own payroll item

Success response example:

```json
{
  "id": 1,
  "payroll_run_id": 3,
  "payroll_month": "2026-04-01",
  "payroll_status": "paid",
  "salary_source": "employee_salaries",
  "base_salary": "2100.00",
  "prorated_base_salary": "2000.00",
  "hourly_rate": "12.5000",
  "daily_rate": "100.0000",
  "eligible_working_days": 20,
  "company_working_days": 21,
  "monthly_working_hours": 168,
  "overtime_normal_hours": "2.00",
  "overtime_weekend_hours": "1.00",
  "overtime_holiday_hours": "0.50",
  "overtime_pay": "60.00",
  "unpaid_leave_units": "1.00",
  "unpaid_leave_deduction": "100.00",
  "raw_net_salary": "1960.00",
  "net_salary": "1960.00",
  "created_at": "2026-04-26T01:10:00+07:00",
  "updated_at": "2026-04-26T01:10:00+07:00"
}
```

Validation error example:

No request-body or query validation applies on this endpoint. An invalid or missing `{payrollItem}` path value returns `404 Not Found`.

Auth or permission error example:

Trying to open another employee’s payslip:

```json
{
  "message": "Forbidden."
}
```
