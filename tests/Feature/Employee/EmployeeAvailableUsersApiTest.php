<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows hr to list users available for employee linking', function () {
    $hr = createEmployeeLinkingActor('hr');
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    $adminRole = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Admin'],
    );

    $availableUser = User::factory()->create([
        'name' => 'Available Person',
        'email' => 'available.person@example.com',
    ]);
    $availableUser->roles()->sync([$employeeRole->id]);

    $linkedUser = User::factory()->create([
        'name' => 'Linked Person',
        'email' => 'linked.person@example.com',
    ]);
    $linkedUser->roles()->sync([$employeeRole->id]);
    createLinkedEmployee($linkedUser);

    $adminUser = User::factory()->create([
        'name' => 'Admin Person',
        'email' => 'admin.person@example.com',
    ]);
    $adminUser->roles()->sync([$adminRole->id]);

    Passport::actingAs($hr);

    $response = $this->getJson('/api/employees/available-users');

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $availableUser->id)
        ->assertJsonPath('data.0.name', 'Available Person')
        ->assertJsonPath('data.0.email', 'available.person@example.com')
        ->assertJsonPath('data.0.roles.0.name', 'employee');

    expect($response->json('data.0'))
        ->toHaveKeys(['id', 'name', 'email', 'roles'])
        ->not->toHaveKeys(['password', 'employee', 'employee_id', 'created_at', 'updated_at', 'email_verified_at']);
});

it('forbids non hr users from listing users available for employee linking', function () {
    $admin = createEmployeeLinkingActor('admin');

    Passport::actingAs($admin);

    $this->getJson('/api/employees/available-users')
        ->assertForbidden();
});

function createEmployeeLinkingActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);
    createLinkedEmployee($user);

    return $user->fresh('roles', 'employee');
}

function createLinkedEmployee(User $user): Employee
{
    $department = Department::query()->create([
        'name' => fake()->unique()->company(),
    ]);
    $position = Position::query()->create([
        'title' => fake()->unique()->jobTitle(),
    ]);

    return Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->unique()->safeEmail(),
        'phone' => '0'.fake()->numerify('#########'),
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ]);
}
