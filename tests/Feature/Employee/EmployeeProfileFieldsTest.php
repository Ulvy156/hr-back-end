<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('stores the new employee personal information fields without breaking existing fields', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'People']);
    $position = Position::query()->create(['title' => 'HR Officer']);
    $employeeUser = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->postJson('/api/employees', [
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'first_name' => 'Nita',
        'last_name' => 'Sok',
        'email' => 'nita.work@example.com',
        'phone' => '012111222',
        'date_of_birth' => '1998-05-10',
        'gender' => 'female',
        'personal_phone' => '098111222',
        'personal_email' => 'nita.personal@example.com',
        'current_address' => 'Phnom Penh',
        'permanent_address' => 'Kampong Cham',
        'id_type' => 'national_id',
        'id_number' => 'ID-2026-001',
        'emergency_contact_name' => 'Sok Dara',
        'emergency_contact_relationship' => 'sibling',
        'emergency_contact_phone' => '011333444',
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('email', 'nita.work@example.com')
        ->assertJsonPath('personal_email', 'nita.personal@example.com')
        ->assertJsonPath('gender', 'female')
        ->assertJsonPath('full_name', 'Nita Sok');

    expect(Employee::query()->first()?->personal_email)->toBe('nita.personal@example.com')
        ->and(Employee::query()->first()?->current_address)->toBe('Phnom Penh')
        ->and(Employee::query()->first()?->id_number)->toBe('ID-2026-001');
});

it('updates the new employee personal information fields and keeps nullable fields backward compatible', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'Operations']);
    $position = Position::query()->create(['title' => 'Coordinator']);
    $employeeUser = User::factory()->create();

    $employee = Employee::query()->create([
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => 'Chan',
        'last_name' => 'Vanna',
        'email' => 'chan.work@example.com',
        'phone' => '012123123',
        'hire_date' => '2025-01-10',
        'status' => 'active',
    ]);

    Passport::actingAs($admin);

    $response = $this->putJson("/api/employees/{$employee->id}", [
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => null,
        'first_name' => 'Chan',
        'last_name' => 'Vanna',
        'email' => 'chan.work@example.com',
        'phone' => '012123123',
        'date_of_birth' => '1995-11-02',
        'gender' => 'male',
        'personal_phone' => '099555666',
        'personal_email' => 'chan.personal@example.com',
        'current_address' => 'Siem Reap',
        'permanent_address' => null,
        'id_type' => 'passport',
        'id_number' => 'P1234567',
        'emergency_contact_name' => 'Kimly',
        'emergency_contact_relationship' => 'spouse',
        'emergency_contact_phone' => '010777888',
        'hire_date' => '2025-01-10',
        'status' => 'active',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('date_of_birth', '1995-11-02T00:00:00.000000Z')
        ->assertJsonPath('personal_phone', '099555666')
        ->assertJsonPath('id_type', 'passport')
        ->assertJsonPath('full_name', 'Chan Vanna');

    expect($employee->fresh()?->permanent_address)->toBeNull()
        ->and($employee->fresh()?->emergency_contact_relationship?->value)->toBe('spouse');
});

it('rejects invalid enum values for employee personal information fields', function () {
    $admin = createEmployeeAdmin();
    $department = Department::query()->create(['name' => 'Compliance']);
    $position = Position::query()->create(['title' => 'Officer']);
    $employeeUser = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->postJson('/api/employees', [
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'first_name' => 'Mara',
        'last_name' => 'Kim',
        'email' => 'mara.work@example.com',
        'phone' => '012000111',
        'gender' => 'invalid_gender',
        'id_type' => 'invalid_id',
        'emergency_contact_relationship' => 'invalid_relationship',
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'gender',
            'id_type',
            'emergency_contact_relationship',
        ]);
});

function createEmployeeAdmin(): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Administrator']
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}
