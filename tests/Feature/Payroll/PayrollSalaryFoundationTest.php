<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Position;
use App\Services\Payroll\EmployeeSalaryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills employee salaries from current active employee positions and is idempotent', function () {
    $employeeWithCurrentSalary = createPayrollSalaryFoundationEmployee([
        'employee_code' => 'EMP001',
        'base_salary' => 650,
        'start_date' => '2026-01-15',
    ]);
    $employeeWithoutValidSalary = createPayrollSalaryFoundationEmployee([
        'employee_code' => 'EMP002',
        'base_salary' => 0,
        'start_date' => '2026-02-01',
    ]);

    $migration = require database_path('migrations/2026_04_25_222507_backfill_employee_salaries_from_active_employee_positions.php');

    $migration->up();
    $migration->up();

    $backfilledSalary = EmployeeSalary::query()
        ->where('employee_id', $employeeWithCurrentSalary->id)
        ->first();

    expect($backfilledSalary)->not->toBeNull()
        ->and($backfilledSalary?->amount)->toBe('650.00')
        ->and($backfilledSalary?->effective_date?->toDateString())->toBe('2026-01-15')
        ->and(EmployeeSalary::query()->where('employee_id', $employeeWithCurrentSalary->id)->count())->toBe(1)
        ->and(EmployeeSalary::query()->where('employee_id', $employeeWithoutValidSalary->id)->exists())->toBeFalse();
});

it('prefers employee salary history over compatibility salary and falls back only when no salary history exists', function () {
    $resolver = app(EmployeeSalaryResolver::class);

    $employeeWithSalaryHistory = createPayrollSalaryFoundationEmployee([
        'employee_code' => 'EMP010',
        'base_salary' => 500,
        'start_date' => '2026-01-01',
    ]);
    EmployeeSalary::query()->create([
        'employee_id' => $employeeWithSalaryHistory->id,
        'amount' => '2100.00',
        'effective_date' => '2026-04-01',
        'end_date' => null,
    ]);

    $employeeFallbackOnly = createPayrollSalaryFoundationEmployee([
        'employee_code' => 'EMP011',
        'base_salary' => 725,
        'start_date' => '2026-01-01',
    ]);

    $resolvedFromSalaryHistory = $resolver->resolveForDate($employeeWithSalaryHistory, '2026-04-20');
    $resolvedFromFallback = $resolver->resolveForDate($employeeFallbackOnly, '2026-04-20');

    expect($resolvedFromSalaryHistory['salary_source'])->toBe('employee_salaries')
        ->and($resolvedFromSalaryHistory['amount'])->toBe('2100.00')
        ->and($resolvedFromSalaryHistory['employee_salary_id'])->not->toBeNull()
        ->and($resolvedFromFallback['salary_source'])->toBe('employee_positions_fallback')
        ->and($resolvedFromFallback['amount'])->toBe('725.00')
        ->and($resolvedFromFallback['employee_salary_id'])->toBeNull();
});

/**
 * @param  array{employee_code?: string, base_salary?: int|float|string, start_date?: string}  $overrides
 */
function createPayrollSalaryFoundationEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => 'Payroll Foundation '.str()->random(6),
    ]);
    $position = Position::query()->create([
        'title' => 'Payroll Foundation Officer '.str()->random(6),
    ]);

    $employee = Employee::query()->create([
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'employee_code' => $overrides['employee_code'] ?? 'EMP'.fake()->unique()->numerify('####'),
        'first_name' => 'Payroll',
        'last_name' => 'Foundation',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $employee->employeePositions()->create([
        'position_id' => $position->id,
        'base_salary' => $overrides['base_salary'] ?? 500,
        'start_date' => $overrides['start_date'] ?? '2026-01-01',
        'end_date' => null,
    ]);

    return $employee->fresh();
}
