<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\EmployeeSalary;
use App\Models\OvertimeRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollTaxRule;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('payroll.tax.dependent_allowance', 150000.0);
    createPayrollRunTaxRule();
});

it('forbids payroll generation without the generate permission', function () {
    $employee = createPayrollRunEmployee([
        'employee_code' => 'EMP403',
        'first_name' => 'No',
        'last_name' => 'Generate',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])->assertForbidden();

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollItem::query()->count())->toBe(0);
});

it('allows hr to generate payroll through role-based permissions', function () {
    $actor = createPayrollRunActorWithRole('hr');
    $employee = createPayrollRunEmployee([
        'employee_code' => 'EMPHR1',
        'first_name' => 'Helen',
        'last_name' => 'Hr',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])
        ->assertCreated()
        ->assertJsonPath('status', PayrollRun::STATUS_DRAFT)
        ->assertJsonPath('employee_count', 1);
});

it('generates a payroll run and payroll items after pre-validation passes when the employee has no dependents', function () {
    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
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

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('employee_count', 1)
        ->assertJsonPath('total_base_salary', '2100.00')
        ->assertJsonPath('total_prorated_base_salary', '2100.00')
        ->assertJsonPath('total_overtime_pay', '0.00')
        ->assertJsonPath('total_unpaid_leave_deduction', '0.00')
        ->assertJsonPath('total_tax_amount', '0.00')
        ->assertJsonPath('total_net_salary', '2100.00');

    expect(PayrollRun::query()->count())->toBe(1)
        ->and(PayrollItem::query()->count())->toBe(1)
        ->and(PayrollRun::query()->first()?->status)->toBe('draft')
        ->and(PayrollItem::query()->first()?->salary_source)->toBe('employee_salaries')
        ->and(PayrollItem::query()->first()?->employee_code_snapshot)->toBe('EMP001')
        ->and(PayrollItem::query()->first()?->employee_name_snapshot)->toBe('Dara Lim')
        ->and(PayrollItem::query()->first()?->tax_amount)->toBe('0.00')
        ->and(PayrollItem::query()->first()?->net_salary)->toBe('2100.00');
});

it('generates payroll successfully when the employee has claimed tax dependents', function () {
    PayrollTaxRule::query()->delete();

    createPayrollRunTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollRunTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
        'employee_code' => 'EMP010',
        'first_name' => 'Claimed',
        'last_name' => 'Dependent',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'spouse',
        'date_of_birth' => '1990-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])
        ->assertCreated()
        ->assertJsonPath('total_tax_amount', '0.00')
        ->assertJsonPath('total_net_salary', '500000.00');

    expect(PayrollItem::query()->first()?->tax_amount)->toBe('0.00')
        ->and(PayrollItem::query()->first()?->net_salary)->toBe('500000.00');
});

it('includes approved overtime requests when generating payroll', function () {
    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
        'employee_code' => 'EMP012',
        'first_name' => 'Overtime',
        'last_name' => 'Included',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '1680.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    OvertimeRequest::query()->create([
        'employee_id' => $employee->id,
        'overtime_date' => '2026-04-14',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Approved overtime.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])
        ->assertCreated()
        ->assertJsonPath('total_overtime_pay', '28.64');

    expect(PayrollItem::query()->first()?->overtime_normal_hours)->toBe('2.00')
        ->and(PayrollItem::query()->first()?->overtime_pay)->toBe('28.64');
});

it('ignores inactive or unclaimed dependents during payroll generation', function () {
    PayrollTaxRule::query()->delete();

    createPayrollRunTaxRule([
        'name' => 'Lower Band',
        'rate_percentage' => '0.00',
        'min_salary' => '0.00',
        'max_salary' => '400000.00',
    ]);
    createPayrollRunTaxRule([
        'name' => 'Middle Band',
        'rate_percentage' => '10.00',
        'min_salary' => '400000.01',
        'max_salary' => null,
    ]);

    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
        'employee_code' => 'EMP011',
        'first_name' => 'Ignored',
        'last_name' => 'Dependent',
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employee->id,
        'amount' => '500000.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2015-01-01',
        'is_active' => false,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => true,
    ]);
    EmployeeDependent::factory()->create([
        'employee_id' => $employee->id,
        'relationship' => 'child',
        'date_of_birth' => '2016-01-01',
        'is_active' => true,
        'is_working' => false,
        'is_student' => false,
        'is_claimed_for_tax' => false,
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])
        ->assertCreated()
        ->assertJsonPath('total_tax_amount', '50000.00')
        ->assertJsonPath('total_net_salary', '450000.00');

    expect(PayrollItem::query()->first()?->tax_amount)->toBe('50000.00')
        ->and(PayrollItem::query()->first()?->net_salary)->toBe('450000.00');
});

it('fails payroll generation when any employee has no valid salary and creates no partial records', function () {
    $actor = createPayrollRunActor();
    $employeeWithSalary = createPayrollRunEmployee([
        'employee_code' => 'EMP001',
        'first_name' => 'Dara',
        'last_name' => 'Lim',
    ]);
    $employeeWithoutSalary = createPayrollRunEmployee([
        'employee_code' => 'EMP002',
        'first_name' => 'Sok',
        'last_name' => 'Chan',
        'base_salary' => 0,
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employeeWithSalary->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Payroll generation failed because some employees have no valid salary.',
            'errors' => [
                [
                    'employee_id' => $employeeWithoutSalary->id,
                    'employee_code' => 'EMP002',
                    'employee_name' => 'Sok Chan',
                    'reason' => 'No valid salary found for selected month.',
                ],
            ],
        ]);

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollItem::query()->count())->toBe(0);
});

it('blocks duplicate payroll generation when an active payroll run already exists for the month', function (string $status) {
    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
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

    PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => $status,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => '2100.00',
        'total_prorated_base_salary' => '2100.00',
        'total_overtime_pay' => '0.00',
        'total_unpaid_leave_deduction' => '0.00',
        'total_tax_amount' => '0.00',
        'total_net_salary' => '2100.00',
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month'])
        ->assertJsonPath('errors.month.0', 'A payroll run already exists for the selected month.');

    expect(PayrollRun::query()->count())->toBe(1)
        ->and(PayrollRun::query()->first()?->status)->toBe($status);
})->with([
    PayrollRun::STATUS_DRAFT,
    PayrollRun::STATUS_APPROVED,
    PayrollRun::STATUS_PAID,
]);

it('allows payroll generation for the same month when the previous payroll run was cancelled', function () {
    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
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

    $cancelledRun = PayrollRun::query()->create([
        'payroll_month' => '2026-04-01',
        'status' => PayrollRun::STATUS_CANCELLED,
        'company_working_days' => 21,
        'monthly_working_hours' => 168,
        'employee_count' => 1,
        'total_base_salary' => '2100.00',
        'total_prorated_base_salary' => '2100.00',
        'total_overtime_pay' => '0.00',
        'total_unpaid_leave_deduction' => '0.00',
        'total_tax_amount' => '0.00',
        'total_net_salary' => '2100.00',
    ]);

    Passport::actingAs($actor);

    $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ])
        ->assertCreated()
        ->assertJsonPath('status', PayrollRun::STATUS_DRAFT)
        ->assertJsonPath('payroll_month', '2026-04-01');

    expect(PayrollRun::query()->count())->toBe(2)
        ->and(PayrollRun::query()->where('status', PayrollRun::STATUS_CANCELLED)->count())->toBe(1)
        ->and(PayrollRun::query()->find($cancelledRun->id)?->status)->toBe(PayrollRun::STATUS_CANCELLED)
        ->and(PayrollRun::query()->where('status', PayrollRun::STATUS_DRAFT)->count())->toBe(1);
});

it('fails payroll generation when no employees are eligible for the selected month', function () {
    $actor = createPayrollRunActor();

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Cannot generate payroll. No eligible employees or working days found for the selected month.',
            'errors' => [
                'month' => [
                    'Cannot generate payroll. No eligible employees or working days found for the selected month.',
                ],
            ],
        ]);

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollItem::query()->count())->toBe(0);
});

it('fails payroll generation when the selected month has zero working days', function () {
    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
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

    collect(CarbonPeriod::create('2026-04-01', '2026-04-30'))
        ->filter(fn (CarbonInterface $date): bool => ! $date->isWeekend())
        ->each(function (CarbonInterface $date): void {
            PublicHoliday::query()->create([
                'name' => 'Holiday '.$date->toDateString(),
                'holiday_date' => $date->toDateString(),
                'year' => 2026,
                'country_code' => 'KH',
                'is_paid' => true,
                'source' => 'test',
            ]);
        });

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Cannot generate payroll. No eligible employees or working days found for the selected month.',
            'errors' => [
                'month' => [
                    'Cannot generate payroll. No eligible employees or working days found for the selected month.',
                ],
            ],
        ]);

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollItem::query()->count())->toBe(0);
});

it('fails payroll generation when monthly working hours resolve to zero', function () {
    $actor = createPayrollRunActor();
    $employee = createPayrollRunEmployee([
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

    $this->mock(PayrollCalculationService::class, function (MockInterface $mock) use ($employee): void {
        $mock->shouldReceive('calculateForEmployee')
            ->atLeast()
            ->once()
            ->andReturnUsing(function (Employee $calculatedEmployee, mixed $month) use ($employee): array {
                expect($calculatedEmployee->id)->toBe($employee->id)
                    ->and((string) $month)->toContain('2026-04');

                return [
                    'employee_id' => $employee->id,
                    'payroll_month' => '2026-04',
                    'month_start' => '2026-04-01',
                    'month_end' => '2026-04-30',
                    'calculation_window_start' => '2026-04-01',
                    'calculation_window_end' => '2026-04-30',
                    'salary_source' => 'employee_salaries',
                    'employee_salary_id' => $employee->employeeSalaries()->firstOrFail()->id,
                    'base_salary' => 2100.0,
                    'prorated_base_salary' => 2100.0,
                    'hourly_rate' => 0.0,
                    'daily_rate' => 100.0,
                    'eligible_working_days' => 21,
                    'company_working_days' => 21,
                    'monthly_working_hours' => 0,
                    'overtime_normal_hours' => 0.0,
                    'overtime_weekend_hours' => 0.0,
                    'overtime_holiday_hours' => 0.0,
                    'overtime_pay' => 0.0,
                    'unpaid_leave_units' => 0.0,
                    'unpaid_leave_deduction' => 0.0,
                    'tax_amount' => 0.0,
                    'gross_net_salary' => 2100.0,
                    'net_salary' => 2100.0,
                ];
            });
    });

    Passport::actingAs($actor);

    $response = $this->postJson('/api/payroll/runs', [
        'month' => '2026-04',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Cannot generate payroll. No eligible employees or working days found for the selected month.',
            'errors' => [
                'month' => [
                    'Cannot generate payroll. No eligible employees or working days found for the selected month.',
                ],
            ],
        ]);

    expect(PayrollRun::query()->count())->toBe(0)
        ->and(PayrollItem::query()->count())->toBe(0);
});

function createPayrollRunActor(): User
{
    Permission::findOrCreate(PermissionName::PayrollRunGenerate->value, 'api');

    $user = User::factory()->create();
    $user->givePermissionTo(PermissionName::PayrollRunGenerate->value);

    return $user;
}

function createPayrollRunActorWithRole(string $roleName): User
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
function createPayrollRunTaxRule(array $overrides = []): PayrollTaxRule
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
 * @param  array{employee_code?: string, first_name?: string, last_name?: string, hire_date?: string, termination_date?: string|null, last_working_date?: string|null, base_salary?: int|float|string}  $overrides
 */
function createPayrollRunEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Run '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Run Officer '.str()->random(6),
    ]);

    $employee = Employee::query()->create([
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'employee_code' => $overrides['employee_code'] ?? 'EMP'.fake()->unique()->numerify('####'),
        'first_name' => $overrides['first_name'] ?? 'Payroll',
        'last_name' => $overrides['last_name'] ?? 'Employee',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => $overrides['hire_date'] ?? '2026-01-01',
        'termination_date' => $overrides['termination_date'] ?? null,
        'last_working_date' => $overrides['last_working_date'] ?? null,
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
