<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Position;
use App\Models\User;
use App\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('employee lists only own payslips with filters', function () {
    [$user, $employee] = createOwnPayslipActor();
    [, $otherEmployee] = createOwnPayslipActor();

    $matchingRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_PAID,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 2,
        'total_base_salary' => 4200,
        'total_prorated_base_salary' => 4200,
        'total_overtime_pay' => 60,
        'total_unpaid_leave_deduction' => 100,
        'total_net_salary' => 4160,
    ]);
    $otherRun = PayrollRun::query()->create([
        'payroll_month' => '2026-03-01',
        'status' => PayrollRun::STATUS_APPROVED,
        'company_working_days' => 20,
        'monthly_working_hours' => 160,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2100,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 2100,
    ]);

    $matchingRun->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => $employee->employee_code,
        'employee_name_snapshot' => $employee->full_name,
        'base_salary' => 2100,
        'prorated_base_salary' => 2100,
        'hourly_rate' => 12.5,
        'daily_rate' => 100,
        'eligible_working_days' => 21,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 2,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 30,
        'unpaid_leave_units' => 0.5,
        'unpaid_leave_deduction' => 50,
        'raw_net_salary' => 2080,
        'net_salary' => 2080,
    ]);
    $otherRun->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => $employee->employee_code,
        'employee_name_snapshot' => $employee->full_name,
        'base_salary' => 2100,
        'prorated_base_salary' => 2100,
        'hourly_rate' => 13.125,
        'daily_rate' => 105,
        'eligible_working_days' => 20,
        'company_working_days' => 20,
        'monthly_working_hours' => 160,
        'overtime_normal_hours' => 0,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 0,
        'unpaid_leave_units' => 0,
        'unpaid_leave_deduction' => 0,
        'raw_net_salary' => 2100,
        'net_salary' => 2100,
    ]);
    $matchingRun->items()->create([
        'employee_id' => $otherEmployee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => $otherEmployee->employee_code,
        'employee_name_snapshot' => $otherEmployee->full_name,
        'base_salary' => 1800,
        'prorated_base_salary' => 1800,
        'hourly_rate' => 10.7143,
        'daily_rate' => 85.7143,
        'eligible_working_days' => 21,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 0,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 0,
        'unpaid_leave_units' => 0,
        'unpaid_leave_deduction' => 0,
        'raw_net_salary' => 1800,
        'net_salary' => 1800,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/payroll/me/payslips?month=2026-04&status=paid&per_page=10');

    $response
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.payroll_month', '2026-04-01')
        ->assertJsonPath('data.0.payroll_status', PayrollRun::STATUS_PAID)
        ->assertJsonPath('data.0.base_salary', '2100.00')
        ->assertJsonPath('data.0.overtime_pay', '30.00')
        ->assertJsonPath('data.0.unpaid_leave_deduction', '50.00')
        ->assertJsonPath('data.0.net_salary', '2080.00');
});

it('employee cannot view another employees payslip', function () {
    [$user] = createOwnPayslipActor();
    [, $otherEmployee] = createOwnPayslipActor();

    $run = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_PAID,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 1800,
        'total_prorated_base_salary' => 1800,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 1800,
    ]);
    $foreignPayslip = $run->items()->create([
        'employee_id' => $otherEmployee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => $otherEmployee->employee_code,
        'employee_name_snapshot' => $otherEmployee->full_name,
        'base_salary' => 1800,
        'prorated_base_salary' => 1800,
        'hourly_rate' => 10.7143,
        'daily_rate' => 85.7143,
        'eligible_working_days' => 21,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 0,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 0,
        'unpaid_leave_units' => 0,
        'unpaid_leave_deduction' => 0,
        'raw_net_salary' => 1800,
        'net_salary' => 1800,
    ]);

    Passport::actingAs($user);

    $this->getJson("/api/payroll/me/payslips/{$foreignPayslip->id}")
        ->assertForbidden();
});

it('enforces own payslip permission', function () {
    $user = User::factory()->create();
    [, $employee] = createOwnPayslipActor();
    $run = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_PAID,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 0,
        'total_base_salary' => 0,
        'total_prorated_base_salary' => 0,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 0,
    ]);
    $payslip = $run->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => $employee->employee_code,
        'employee_name_snapshot' => $employee->full_name,
        'base_salary' => 0,
        'prorated_base_salary' => 0,
        'hourly_rate' => 0,
        'daily_rate' => 0,
        'eligible_working_days' => 0,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 0,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 0,
        'unpaid_leave_units' => 0,
        'unpaid_leave_deduction' => 0,
        'raw_net_salary' => 0,
        'net_salary' => 0,
    ]);

    Passport::actingAs($user);

    $this->getJson('/api/payroll/me/payslips')->assertForbidden();
    $this->getJson("/api/payroll/me/payslips/{$payslip->id}")->assertForbidden();
});

it('returns own payslip detail and audits the view', function () {
    [$user, $employee] = createOwnPayslipActor();
    $run = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_PAID,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2000,
        'total_overtime_pay' => 60,
        'total_unpaid_leave_deduction' => 100,
        'total_net_salary' => 1960,
    ]);
    $payslip = $run->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_salaries',
        'employee_code_snapshot' => $employee->employee_code,
        'employee_name_snapshot' => $employee->full_name,
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

    Passport::actingAs($user);

    $this->getJson("/api/payroll/me/payslips/{$payslip->id}")
        ->assertOk()
        ->assertJsonPath('payroll_month', '2026-04-01')
        ->assertJsonPath('payroll_status', PayrollRun::STATUS_PAID)
        ->assertJsonPath('salary_source', 'employee_salaries')
        ->assertJsonPath('base_salary', '2100.00')
        ->assertJsonPath('prorated_base_salary', '2000.00')
        ->assertJsonPath('overtime_weekend_hours', '1.00')
        ->assertJsonPath('unpaid_leave_units', '1.00')
        ->assertJsonPath('net_salary', '1960.00');

    expect(Activity::query()->where('event', 'payslip_viewed')->exists())->toBeTrue();
});

function createOwnPayslipActor(): array
{
    Permission::findOrCreate(PermissionName::PayrollPayslipViewOwn->value, 'api');

    $department = Department::query()->create([
        'name' => 'Payslip Department '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payslip Officer '.str()->random(6),
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo(PermissionName::PayrollPayslipViewOwn->value);

    $employee = Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'employee_code' => 'EMP'.fake()->unique()->numerify('####'),
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    return [$user, $employee];
}
