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

uses(RefreshDatabase::class);

it('lists payroll runs with month and status filters', function () {
    $actor = createPayrollRunViewActor();

    PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_DRAFT,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 2,
        'total_base_salary' => 4200,
        'total_prorated_base_salary' => 4100,
        'total_overtime_pay' => 120,
        'total_unpaid_leave_deduction' => 50,
        'total_net_salary' => 4170,
    ]);
    PayrollRun::query()->create([
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

    Passport::actingAs($actor);

    $response = $this->getJson('/api/payroll/runs?month=2026-04&status=draft&per_page=10');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.payroll_month', '2026-04-01')
        ->assertJsonPath('data.0.status', PayrollRun::STATUS_DRAFT)
        ->assertJsonPath('data.0.employee_count', 2)
        ->assertJsonPath('data.0.total_net_salary', '4170.00')
        ->assertJsonPath('meta.total', 1);

    expect($response->json('data.0.items'))->toBeNull();
});

it('returns payroll run detail with payroll items only', function () {
    $actor = createPayrollRunViewActor();
    $employee = createPayrollRunReadEmployee([
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

    $response = $this->getJson("/api/payroll/runs/{$payrollRun->id}");

    $response
        ->assertOk()
        ->assertJsonPath('id', $payrollRun->id)
        ->assertJsonPath('status', PayrollRun::STATUS_APPROVED)
        ->assertJsonPath('items.0.employee_code', 'EMP001')
        ->assertJsonPath('items.0.employee_name', 'Dara Lim')
        ->assertJsonPath('items.0.base_salary', '2100.00')
        ->assertJsonPath('items.0.prorated_base_salary', '2000.00')
        ->assertJsonPath('items.0.overtime_pay', '60.00')
        ->assertJsonPath('items.0.unpaid_leave_deduction', '100.00')
        ->assertJsonPath('items.0.net_salary', '1960.00');

    expect($response->json('items.0.employee'))->toBeNull()
        ->and($response->json('items.0.email'))->toBeNull()
        ->and($response->json('items.0.phone'))->toBeNull();
});

it('enforces payroll run view permission for list and detail', function () {
    $user = User::factory()->create();
    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_DRAFT,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2100,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 2100,
    ]);

    Passport::actingAs($user);

    $this->getJson('/api/payroll/runs')->assertForbidden();
    $this->getJson("/api/payroll/runs/{$payrollRun->id}")->assertForbidden();
});

function createPayrollRunViewActor(): User
{
    Permission::findOrCreate(PermissionName::PayrollRunView->value, 'api');

    $user = User::factory()->create();
    $user->givePermissionTo(PermissionName::PayrollRunView->value);

    return $user;
}

/**
 * @param  array{employee_code?: string, first_name?: string, last_name?: string}  $overrides
 */
function createPayrollRunReadEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Read '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Read Officer '.str()->random(6),
    ]);

    return Employee::query()->create([
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
}
