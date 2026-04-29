<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Permission;
use App\Models\Position;
use App\Models\User;
use App\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('lists payroll salaries for authorized users', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryView]);
    $employee = createPayrollSalaryEmployee([
        'employee_code' => 'EMP001',
        'first_name' => 'Dara',
        'last_name' => 'Lim',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '650.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->getJson('/api/payroll/salaries?employee_id='.$employee->id.'&effective_on=2026-04-15')
        ->assertOk()
        ->assertJsonPath('data.0.employee_id', $employee->id)
        ->assertJsonPath('data.0.employee.employee_code', 'EMP001')
        ->assertJsonPath('data.0.employee.full_name', 'Dara Lim')
        ->assertJsonPath('data.0.amount', '650.00')
        ->assertJsonPath('data.0.status', 'current')
        ->assertJsonPath('meta.total', 1);
});

it('filters payroll salaries by status, employee, and effective date while keeping pagination', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryView]);
    $employee = createPayrollSalaryEmployee([
        'employee_code' => 'EMP100',
        'first_name' => 'Status',
        'last_name' => 'Current',
    ]);
    $otherEmployee = createPayrollSalaryEmployee([
        'employee_code' => 'EMP200',
        'first_name' => 'Other',
        'last_name' => 'Employee',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500.00',
        'effective_date' => '2026-01-01',
        'end_date' => '2026-03-31',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '700.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $otherEmployee->id,
        'amount' => '900.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->getJson('/api/payroll/salaries?status=current&employee_id='.$employee->id.'&effective_date=2026-04-01&per_page=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee_id', $employee->id)
        ->assertJsonPath('data.0.amount', '700.00')
        ->assertJsonPath('data.0.effective_date', '2026-04-01')
        ->assertJsonPath('data.0.end_date', null)
        ->assertJsonPath('data.0.status', 'current');
});

it('filters ended payroll salaries by status', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryView]);
    $employee = createPayrollSalaryEmployee();

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '550.00',
        'effective_date' => '2026-01-01',
        'end_date' => '2026-02-28',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '650.00',
        'effective_date' => '2026-03-01',
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->getJson('/api/payroll/salaries?status=ended&per_page=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.amount', '550.00')
        ->assertJsonPath('data.0.status', 'ended');
});

it('treats status all as no status filter', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryView]);
    $employee = createPayrollSalaryEmployee();

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '550.00',
        'effective_date' => '2026-01-01',
        'end_date' => '2026-02-28',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '650.00',
        'effective_date' => '2026-03-01',
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->getJson('/api/payroll/salaries?status=all&per_page=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('creates a current salary and syncs the active employee position salary', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee();

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 725.50,
        'effective_date' => now()->toDateString(),
        'end_date' => null,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('employee_id', $employee->id)
        ->assertJsonPath('amount', '725.50');

    expect($employee->employeeSalaries()->count())->toBe(1)
        ->and($employee->employeeSalaries()->first()?->amount)->toBe('725.50')
        ->and($employee->employeePositions()->whereNull('end_date')->first()?->base_salary)->toBe('725.50')
        ->and(Activity::query()->where('log_name', 'payroll')->where('event', 'payroll_salary_created')->exists())->toBeTrue();
});

it('does not sync a future dated salary to the active employee position salary', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee(['base_salary' => 500]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 900,
        'effective_date' => now()->addMonth()->startOfMonth()->toDateString(),
        'end_date' => null,
    ])->assertCreated();

    expect($employee->employeePositions()->whereNull('end_date')->first()?->base_salary)->toBe('500.00');
});

it('replaces the current salary when creating a later effective salary', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee(['base_salary' => 600]);
    $currentEffectiveDate = now()->subMonths(6)->startOfMonth();
    $replacementEffectiveDate = now()->startOfMonth();

    $currentSalary = EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '600.00',
        'effective_date' => $currentEffectiveDate->toDateString(),
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 800,
        'effective_date' => $replacementEffectiveDate->toDateString(),
        'end_date' => null,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('employee_id', $employee->id)
        ->assertJsonPath('amount', '800.00')
        ->assertJsonPath('effective_date', $replacementEffectiveDate->toDateString())
        ->assertJsonPath('end_date', null);

    $replacementSalary = EmployeeSalary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('effective_date', $replacementEffectiveDate->toDateString())
        ->firstOrFail();

    expect($currentSalary->fresh()?->end_date?->toDateString())
        ->toBe($replacementEffectiveDate->copy()->subDay()->toDateString())
        ->and($employee->employeeSalaries()->count())->toBe(2)
        ->and($employee->employeeSalaries()->activeOn(today())->count())->toBe(1)
        ->and($employee->employeeSalaries()->activeOn(today())->first()?->id)->toBe($replacementSalary->id)
        ->and($employee->employeePositions()->whereNull('end_date')->first()?->base_salary)->toBe('800.00')
        ->and(Activity::query()->where('log_name', 'payroll')->where('event', 'payroll_salary_ended')->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'payroll')->where('event', 'payroll_salary_created')->exists())->toBeTrue();
});

it('rejects a replacement salary with the same effective date as the current salary', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee();
    $currentEffectiveDate = now()->startOfMonth();

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '600.00',
        'effective_date' => $currentEffectiveDate->toDateString(),
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 800,
        'effective_date' => $currentEffectiveDate->toDateString(),
        'end_date' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_date'])
        ->assertJsonPath(
            'errors.effective_date.0',
            'The effective date must be later than the employee\'s current salary effective date.'
        );
});

it('rejects a replacement salary with an earlier effective date than the current salary', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee();
    $currentEffectiveDate = now()->startOfMonth();

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '600.00',
        'effective_date' => $currentEffectiveDate->toDateString(),
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 800,
        'effective_date' => $currentEffectiveDate->copy()->subDay()->toDateString(),
        'end_date' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_date'])
        ->assertJsonPath(
            'errors.effective_date.0',
            'The effective date must be later than the employee\'s current salary effective date.'
        );
});

it('keeps overlap validation for non-current salary conflicts', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee();

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '600.00',
        'effective_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 800,
        'effective_date' => '2026-06-15',
        'end_date' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_date']);
});

it('updates an existing salary and keeps current position salary in sync', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryManage]);
    $employee = createPayrollSalaryEmployee(['base_salary' => 620]);
    $salary = EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '620.00',
        'effective_date' => now()->subMonth()->startOfMonth()->toDateString(),
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->patchJson("/api/payroll/salaries/{$salary->id}", [
        'amount' => 680,
    ])
        ->assertOk()
        ->assertJsonPath('amount', '680.00');

    expect($salary->fresh()?->amount)->toBe('680.00')
        ->and($employee->employeePositions()->whereNull('end_date')->first()?->base_salary)->toBe('680.00')
        ->and(Activity::query()->where('log_name', 'payroll')->where('event', 'payroll_salary_updated')->exists())->toBeTrue();
});

it('forbids users without payroll salary permissions', function () {
    $user = User::factory()->create();
    $employee = createPayrollSalaryEmployee();
    $salary = EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '700.00',
        'effective_date' => now()->subMonth()->startOfMonth()->toDateString(),
        'end_date' => null,
    ]);

    Passport::actingAs($user);

    $this->getJson('/api/payroll/salaries')
        ->assertForbidden();

    $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 700,
        'effective_date' => now()->toDateString(),
    ])->assertForbidden();

    $this->patchJson("/api/payroll/salaries/{$salary->id}", [
        'amount' => 750,
    ])->assertForbidden();
});

it('allows payroll salary viewers to list salaries but forbids salary management actions', function () {
    $actor = createPayrollSalaryActor([PermissionName::PayrollSalaryView]);
    $employee = createPayrollSalaryEmployee();
    $salary = EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '650.00',
        'effective_date' => now()->subMonth()->startOfMonth()->toDateString(),
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->getJson('/api/payroll/salaries')
        ->assertOk();

    $this->postJson('/api/payroll/salaries', [
        'employee_id' => $employee->id,
        'amount' => 700,
        'effective_date' => now()->toDateString(),
    ])->assertForbidden();

    $this->patchJson("/api/payroll/salaries/{$salary->id}", [
        'amount' => 725,
    ])->assertForbidden();
});

/**
 * @param  array<int, PermissionName>  $permissions
 */
function createPayrollSalaryActor(array $permissions): User
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

/**
 * @param  array{employee_code?: string, first_name?: string, last_name?: string, base_salary?: int|float|string}  $overrides
 */
function createPayrollSalaryEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Department '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Officer '.str()->random(6),
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
