<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollRun;
use App\Models\PayrollTaxRule;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    createPayrollRunActionTaxRule();
});

it('approves a draft payroll run', function () {
    $actor = createPayrollRunActionActor([PermissionName::PayrollRunApprove]);
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

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_APPROVED);

    expect($payrollRun->fresh()?->status)->toBe(PayrollRun::STATUS_APPROVED)
        ->and(Activity::query()->where('event', 'payroll_approved')->exists())->toBeTrue();
});

it('marks an approved payroll run as paid', function () {
    $actor = createPayrollRunActionActor([PermissionName::PayrollRunMarkPaid]);
    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_APPROVED,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2100,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 2100,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/mark-paid")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_PAID);

    expect($payrollRun->fresh()?->status)->toBe(PayrollRun::STATUS_PAID)
        ->and(Activity::query()->where('event', 'payroll_marked_paid')->exists())->toBeTrue();
});

it('cancels a draft or approved payroll run', function (string $initialStatus) {
    $actor = createPayrollRunActionActor([PermissionName::PayrollRunCancel]);
    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => $initialStatus,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2100,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 2100,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_CANCELLED);

    expect($payrollRun->fresh()?->status)->toBe(PayrollRun::STATUS_CANCELLED)
        ->and(Activity::query()->where('event', 'payroll_cancelled')->exists())->toBeTrue();
})->with([
    PayrollRun::STATUS_DRAFT,
    PayrollRun::STATUS_APPROVED,
]);

it('regenerates a draft payroll run by deleting old items and rebuilding them', function () {
    $actor = createPayrollRunActionActor([PermissionName::PayrollRunRegenerate]);
    $employee = createPayrollRunActionEmployee([
        'employee_code' => 'EMP001',
        'first_name' => 'Dara',
        'last_name' => 'Lim',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_DRAFT,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 9999,
        'total_prorated_base_salary' => 9999,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 9999,
    ]);
    $payrollRun->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_positions_fallback',
        'employee_code_snapshot' => 'OLD',
        'employee_name_snapshot' => 'Old Name',
        'base_salary' => 9999,
        'prorated_base_salary' => 9999,
        'hourly_rate' => 1,
        'daily_rate' => 1,
        'eligible_working_days' => 1,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 0,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 0,
        'unpaid_leave_units' => 0,
        'unpaid_leave_deduction' => 0,
        'raw_net_salary' => 9999,
        'net_salary' => 9999,
    ]);
    $originalItemId = $payrollRun->items()->firstOrFail()->id;

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/regenerate")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_DRAFT)
        ->assertJsonPath('total_base_salary', '2100.00')
        ->assertJsonPath('total_tax_amount', '0.00')
        ->assertJsonPath('total_net_salary', '2100.00');

    expect($payrollRun->fresh()?->total_base_salary)->toBe('2100.00')
        ->and($payrollRun->items()->count())->toBe(1)
        ->and($payrollRun->items()->first()?->id)->not->toBe($originalItemId)
        ->and($payrollRun->items()->first()?->employee_code_snapshot)->toBe('EMP001')
        ->and($payrollRun->items()->first()?->salary_source)->toBe('employee_salaries')
        ->and(Activity::query()->where('event', 'payroll_regenerated')->exists())->toBeTrue();
});

it('rejects invalid payroll run transitions', function (string $endpoint, string $permission, string $initialStatus, string $errorKey, string $message) {
    $actor = createPayrollRunActionActor([PermissionName::from($permission)]);
    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => $initialStatus,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2100,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 2100,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/{$endpoint}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey])
        ->assertJsonPath("errors.{$errorKey}.0", $message);
})->with([
    ['approve', PermissionName::PayrollRunApprove->value, PayrollRun::STATUS_APPROVED, 'status', 'Only draft payroll runs can be approved.'],
    ['mark-paid', PermissionName::PayrollRunMarkPaid->value, PayrollRun::STATUS_DRAFT, 'status', 'Only approved payroll runs can be marked as paid.'],
    ['cancel', PermissionName::PayrollRunCancel->value, PayrollRun::STATUS_PAID, 'status', 'Only draft or approved payroll runs can be cancelled.'],
    ['regenerate', PermissionName::PayrollRunRegenerate->value, PayrollRun::STATUS_APPROVED, 'status', 'Only draft payroll runs can be regenerated.'],
]);

it('does not allow regenerate with generate permission alone', function () {
    $actor = createPayrollRunActionActor([PermissionName::PayrollRunGenerate]);
    $employee = createPayrollRunActionEmployee([
        'employee_code' => 'EMPGEN',
        'first_name' => 'Generate',
        'last_name' => 'Only',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_DRAFT,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 9999,
        'total_prorated_base_salary' => 9999,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 9999,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/regenerate")
        ->assertForbidden();
});

it('forbids regular hr users from restricted payroll actions', function (string $endpoint) {
    $actor = createPayrollRunActionActorWithRole('hr');
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

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/{$endpoint}")
        ->assertForbidden();
})->with([
    'approve',
    'cancel',
    'regenerate',
]);

it('allows hr leadership roles to approve payroll runs', function (string $roleName) {
    $actor = createPayrollRunActionActorWithRole($roleName);
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

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_APPROVED);
})->with([
    'hr_head',
    'hr_manager',
    'admin',
]);

it('allows hr leadership roles to cancel payroll runs', function (string $roleName) {
    $actor = createPayrollRunActionActorWithRole($roleName);
    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_APPROVED,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 2100,
        'total_prorated_base_salary' => 2100,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 2100,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_CANCELLED);
})->with([
    'hr_head',
    'hr_manager',
    'admin',
]);

it('allows hr leadership roles to regenerate payroll runs', function (string $roleName) {
    $actor = createPayrollRunActionActorWithRole($roleName);
    $employee = createPayrollRunActionEmployee([
        'employee_code' => 'EMP-ROLE-'.$roleName,
        'first_name' => 'Role',
        'last_name' => 'Payroll',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $payrollRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_DRAFT,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => 9999,
        'total_prorated_base_salary' => 9999,
        'total_overtime_pay' => 0,
        'total_unpaid_leave_deduction' => 0,
        'total_net_salary' => 9999,
    ]);
    $payrollRun->items()->create([
        'employee_id' => $employee->id,
        'employee_salary_id' => null,
        'salary_source' => 'employee_positions_fallback',
        'employee_code_snapshot' => 'OLD',
        'employee_name_snapshot' => 'Old Name',
        'base_salary' => 9999,
        'prorated_base_salary' => 9999,
        'hourly_rate' => 1,
        'daily_rate' => 1,
        'eligible_working_days' => 1,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'overtime_normal_hours' => 0,
        'overtime_weekend_hours' => 0,
        'overtime_holiday_hours' => 0,
        'overtime_pay' => 0,
        'unpaid_leave_units' => 0,
        'unpaid_leave_deduction' => 0,
        'raw_net_salary' => 9999,
        'net_salary' => 9999,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/runs/{$payrollRun->id}/regenerate")
        ->assertOk()
        ->assertJsonPath('status', PayrollRun::STATUS_DRAFT);
})->with([
    'hr_head',
    'hr_manager',
    'admin',
]);

/**
 * @param  array<int, PermissionName>  $permissions
 */
function createPayrollRunActionActor(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission->value, 'api');
    }

    $user = User::factory()->create();
    $user->givePermissionTo(array_map(
        static fn (PermissionName $permission): string => $permission->value,
        $permissions,
    ));

    return $user;
}

function createPayrollRunActionActorWithRole(string $roleName): User
{
    app(RoleAndPermissionSeeder::class)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->assignRole(Role::findByName($roleName, 'api'));

    return $user->fresh();
}

/**
 * @param  array{name?: string, rate_percentage?: string, min_salary?: string, max_salary?: string|null, is_active?: bool, effective_from?: string, effective_to?: string|null}  $overrides
 */
function createPayrollRunActionTaxRule(array $overrides = []): PayrollTaxRule
{
    return PayrollTaxRule::query()->create([
        'name' => $overrides['name'] ?? 'Default Payroll Tax Rule',
        'rate_percentage' => $overrides['rate_percentage'] ?? '0.00',
        'min_salary' => $overrides['min_salary'] ?? '0.00',
        'max_salary' => $overrides['max_salary'] ?? null,
        'is_active' => $overrides['is_active'] ?? true,
        'effective_from' => $overrides['effective_from'] ?? '2026-01-01',
        'effective_to' => $overrides['effective_to'] ?? null,
    ]);
}

/**
 * @param  array{employee_code?: string, first_name?: string, last_name?: string, base_salary?: int|float|string}  $overrides
 */
function createPayrollRunActionEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Action '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Action Officer '.str()->random(6),
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
        'base_salary' => $overrides['base_salary'] ?? 500,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    return $employee->fresh();
}
