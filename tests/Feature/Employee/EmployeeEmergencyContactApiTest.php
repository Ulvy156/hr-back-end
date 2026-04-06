<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows hr to create list update and delete employee emergency contacts independently', function () {
    $hr = createEmployeeEmergencyContactActor('hr');
    $employee = createEmployeeForEmergencyContactApi();

    Passport::actingAs($hr);

    $createResponse = $this->postJson("/api/employees/{$employee->id}/emergency-contacts", [
        'name' => 'Primary Contact',
        'relationship' => 'spouse',
        'phone' => '011111111',
        'email' => 'primary@example.com',
        'is_primary' => true,
    ]);

    $contactId = $createResponse->json('id');

    $createResponse
        ->assertCreated()
        ->assertJsonPath('name', 'Primary Contact')
        ->assertJsonPath('relationship', 'spouse')
        ->assertJsonPath('is_primary', true);

    $this->getJson("/api/employees/{$employee->id}/emergency-contacts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $contactId)
        ->assertJsonPath('data.0.phone', '011111111');

    $this->putJson("/api/employees/{$employee->id}/emergency-contacts/{$contactId}", [
        'name' => 'Updated Contact',
        'relationship' => 'guardian',
        'phone' => '077123456',
        'email' => 'updated@example.com',
        'is_primary' => false,
    ])
        ->assertOk()
        ->assertJsonPath('name', 'Updated Contact')
        ->assertJsonPath('relationship', 'guardian')
        ->assertJsonPath('is_primary', true);

    $this->deleteJson("/api/employees/{$employee->id}/emergency-contacts/{$contactId}")
        ->assertNoContent();

    $this->getJson("/api/employees/{$employee->id}/emergency-contacts")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects invalid emergency contact relationship values', function () {
    $admin = createEmployeeEmergencyContactActor('admin');
    $employee = createEmployeeForEmergencyContactApi();

    Passport::actingAs($admin);

    $this->postJson("/api/employees/{$employee->id}/emergency-contacts", [
        'name' => 'Invalid Contact',
        'relationship' => 'invalid',
        'phone' => '011111111',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['relationship']);
});

function createEmployeeEmergencyContactActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}

function createEmployeeForEmergencyContactApi(): Employee
{
    $department = Department::query()->create(['name' => 'People']);
    $position = Position::query()->create(['title' => 'Officer']);
    $user = User::factory()->create();

    return Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => 'Mara',
        'last_name' => 'Kim',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2024-01-15',
        'status' => 'active',
    ]);
}
