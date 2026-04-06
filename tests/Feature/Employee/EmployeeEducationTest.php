<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows admin to create, list, update, and delete employee education records', function () {
    $admin = createEmployeeEducationAdmin();
    $employee = createEmployeeForEducation();

    Passport::actingAs($admin);

    $createResponse = $this->postJson("/api/employees/{$employee->id}/educations", [
        'institution_name' => 'Royal University of Phnom Penh',
        'education_level' => 'bachelor',
        'degree' => 'Bachelor of Business Administration',
        'field_of_study' => 'Human Resource Management',
        'start_date' => '2018-10-01',
        'end_date' => '2022-06-30',
        'graduation_year' => 2022,
        'grade' => '3.7 GPA',
        'description' => 'Graduated with a focus on people operations.',
    ]);

    $educationId = $createResponse->json('id');

    $createResponse
        ->assertCreated()
        ->assertJsonPath('employee_id', $employee->id)
        ->assertJsonPath('institution_name', 'Royal University of Phnom Penh')
        ->assertJsonPath('education_level', 'bachelor');

    $this->getJson("/api/employees/{$employee->id}/educations")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $educationId)
        ->assertJsonPath('0.degree', 'Bachelor of Business Administration');

    $this->getJson("/api/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonCount(1, 'educations')
        ->assertJsonPath('educations.0.id', $educationId)
        ->assertJsonPath('educations.0.institution_name', 'Royal University of Phnom Penh');

    $this->putJson("/api/employees/{$employee->id}/educations/{$educationId}", [
        'institution_name' => 'Royal University of Phnom Penh',
        'education_level' => 'master',
        'degree' => 'Master of Management',
        'field_of_study' => 'Management',
        'start_date' => '2023-01-15',
        'end_date' => '2024-12-15',
        'graduation_year' => 2024,
        'grade' => 'Distinction',
        'description' => 'Updated postgraduate record.',
    ])
        ->assertOk()
        ->assertJsonPath('education_level', 'master')
        ->assertJsonPath('degree', 'Master of Management');

    $this->deleteJson("/api/employees/{$employee->id}/educations/{$educationId}")
        ->assertNoContent();

    $this->getJson("/api/employees/{$employee->id}/educations")
        ->assertOk()
        ->assertJsonCount(0);
});

it('rejects invalid education level values', function () {
    $admin = createEmployeeEducationAdmin();
    $employee = createEmployeeForEducation();

    Passport::actingAs($admin);

    $this->postJson("/api/employees/{$employee->id}/educations", [
        'institution_name' => 'National University',
        'education_level' => 'invalid_level',
        'degree' => 'Bachelor of Science',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['education_level']);
});

function createEmployeeEducationAdmin(): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Administrator']
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}

function createEmployeeForEducation(): Employee
{
    $department = Department::query()->create([
        'name' => 'Operations',
    ]);

    $position = Position::query()->create([
        'title' => 'Coordinator',
    ]);

    $user = User::factory()->create();

    return Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => 'Dara',
        'last_name' => 'Sok',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '012345678',
        'hire_date' => '2024-01-15',
        'status' => 'active',
    ]);
}
