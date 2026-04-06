<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows admin to create list update and delete employee positions independently', function () {
    $admin = createEmployeePositionActor('admin');
    [$employee, $currentPosition, $previousPosition, $nextPosition] = createEmployeeForPositionApi();

    Passport::actingAs($admin);

    $createResponse = $this->postJson("/api/employees/{$employee->id}/positions", [
        'position_id' => $previousPosition->id,
        'base_salary' => 450,
        'start_date' => '2023-10-01',
        'end_date' => '2024-03-31',
    ]);

    $employeePositionId = $createResponse->json('id');

    $createResponse
        ->assertCreated()
        ->assertJsonPath('position.title', 'Support Intern')
        ->assertJsonPath('is_current', false);

    $this->getJson("/api/employees/{$employee->id}/positions")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.position.title', 'Support Agent');

    $currentEmployeePosition = EmployeePosition::query()
        ->where('employee_id', $employee->id)
        ->where('position_id', $currentPosition->id)
        ->firstOrFail();

    $updatedCurrentResponse = $this->putJson("/api/employees/{$employee->id}/positions/{$currentEmployeePosition->id}", [
        'position_id' => $nextPosition->id,
        'base_salary' => 800,
        'start_date' => '2025-04-01',
        'end_date' => null,
    ]);

    $updatedCurrentResponse
        ->assertOk()
        ->assertJsonPath('position.title', 'Senior Support Agent')
        ->assertJsonPath('is_current', true);

    expect($employee->fresh()?->current_position_id)->toBe($nextPosition->id);

    $this->deleteJson("/api/employees/{$employee->id}/positions/{$employeePositionId}")
        ->assertNoContent();

    $this->getJson("/api/employees/{$employee->id}/positions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.position.title', 'Senior Support Agent');
});

it('rejects creating a second current employee position', function () {
    $admin = createEmployeePositionActor('admin');
    [$employee, $currentPosition, $previousPosition] = createEmployeeForPositionApi();

    Passport::actingAs($admin);

    $this->postJson("/api/employees/{$employee->id}/positions", [
        'position_id' => $previousPosition->id,
        'base_salary' => 900,
        'start_date' => '2025-01-01',
        'end_date' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

function createEmployeePositionActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}

/**
 * @return array{0: Employee, 1: Position, 2: Position, 3: Position}
 */
function createEmployeeForPositionApi(): array
{
    $department = Department::query()->create(['name' => 'Support']);
    $currentPosition = Position::query()->create(['title' => 'Support Agent']);
    $previousPosition = Position::query()->create(['title' => 'Support Intern']);
    $nextPosition = Position::query()->create(['title' => 'Senior Support Agent']);
    $user = User::factory()->create();

    $employee = Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $currentPosition->id,
        'first_name' => 'Dara',
        'last_name' => 'Lim',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2024-01-15',
        'status' => 'active',
    ]);

    $employee->employeePositions()->create([
        'position_id' => $currentPosition->id,
        'base_salary' => 550,
        'start_date' => '2024-04-01',
        'end_date' => null,
    ]);

    return [$employee, $currentPosition, $previousPosition, $nextPosition];
}
