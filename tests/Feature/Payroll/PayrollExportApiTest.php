<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Position;
use App\Models\User;
use App\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('enforces payroll export permission', function () {
    $user = User::factory()->create();
    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_DRAFT,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 0,
        'total_base_salary' => 0,
        'total_prorated_base_salary' => 0,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 0,
    ]);

    Passport::actingAs($user);

    $this->get("/api/payroll/runs/{$payrollRun->id}/export/excel")
        ->assertForbidden();
});

it('returns a payroll export download response and audits the export', function () {
    $actor = createPayrollExportActor();
    $employee = createPayrollExportEmployee([
        'employee_code' => 'EMP001',
        'first_name' => 'Dara',
        'last_name' => 'Lim',
    ]);

    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_APPROVED,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2000,
        'total_overtime_pay' => 60,
        'total_unpaid_leave_deduction' => 100,
        'total_net_salary' => 1960,
    ]);
    $payrollRun->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => 'EMP001',
        'employee_name_snapshot' => 'Dara Lim',
        'base_salary' => 2100,
        'prorated_base_salary' => 2000,
        'hourly_rate' => 12.5,
        'daily_rate' => 100,
        'eligible_working_days' => 20,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 2,
        'overtime_weekend_hours' => 1,
        'overtime_holiday_hours' => 0.5,
        'overtime_pay' => 60,
        'unpaid_leave_units' => 1,
        'unpaid_leave_deduction' => 100,
        'raw_net_salary' => 1960,
        'net_salary' => 1960,
    ]);

    Passport::actingAs($actor);

    $response = $this->get("/api/payroll/runs/{$payrollRun->id}/export/excel");

    $response
        ->assertOk()
        ->assertDownload('payroll-run-2026-04.xlsx');

    $zip = new ZipArchive;
    $file = $response->baseResponse->getFile()->getPathname();
    $zip->open($file);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheetXml)
        ->toContain('2026-04-01')
        ->toContain('approved')
        ->toContain('EMP001')
        ->toContain('Dara Lim')
        ->toContain('2100.00')
        ->toContain('2000.00')
        ->toContain('2.00')
        ->toContain('1.00')
        ->toContain('0.50')
        ->toContain('60.00')
        ->toContain('100.00')
        ->toContain('1960.00');

    expect(Activity::query()->where('event', 'payroll_exported')->exists())->toBeTrue();
});

it('exports stored payroll snapshots without recalculating from current salary data', function () {
    $actor = createPayrollExportActor();
    $employee = createPayrollExportEmployee([
        'employee_code' => 'EMP099',
        'first_name' => 'Sok',
        'last_name' => 'Chan',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '9999.99',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_PAID,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 1234.56,
        'total_prorated_base_salary' => 1200.12,
        'total_overtime_pay' => 45.67,
        'total_unpaid_leave_deduction' => 89.01,
        'total_net_salary' => 1156.78,
    ]);
    $payrollRun->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => 'EMP099',
        'employee_name_snapshot' => 'Sok Chan',
        'base_salary' => 1234.56,
        'prorated_base_salary' => 1200.12,
        'hourly_rate' => 7.3498,
        'daily_rate' => 58.7886,
        'eligible_working_days' => 21,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 1.25,
        'overtime_weekend_hours' => 0.75,
        'overtime_holiday_hours' => 0.50,
        'overtime_pay' => 45.67,
        'unpaid_leave_units' => 1.50,
        'unpaid_leave_deduction' => 89.01,
        'raw_net_salary' => 1156.78,
        'net_salary' => 1156.78,
    ]);

    $employee->employeePositions()->firstOrFail()->update([
        'base_salary' => 8888.88,
    ]);

    Passport::actingAs($actor);

    $response = $this->get("/api/payroll/runs/{$payrollRun->id}/export/excel");

    $response->assertOk();

    $zip = new ZipArchive;
    $file = $response->baseResponse->getFile()->getPathname();
    $zip->open($file);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheetXml)
        ->toContain('1234.56')
        ->toContain('1200.12')
        ->toContain('45.67')
        ->toContain('89.01')
        ->toContain('1156.78')
        ->not->toContain('9999.99')
        ->not->toContain('8888.88');
});

function createPayrollExportActor(): User
{
    Permission::findOrCreate(PermissionName::PayrollExport->value, 'api');

    $user = User::factory()->create();
    $user->givePermissionTo(PermissionName::PayrollExport->value);

    return $user;
}

/**
 * @param  array{employee_code?: string, first_name?: string, last_name?: string}  $overrides
 */
function createPayrollExportEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Export '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Export Officer '.str()->random(6),
    ]);

    $employee = Employee::query()->create([
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'employee_code' => $overrides['employee_code'] ?? 'EMP'.fake()->unique()->numerify('####'),
        'first_name' => $overrides['first_name'] ?? 'Payroll',
        'last_name' => $overrides['last_name'] ?? 'Employee',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $employee->employeePositions()->create([
        'position_id' => $position->id,
        'base_salary' => 500,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    return $employee->fresh();
}
