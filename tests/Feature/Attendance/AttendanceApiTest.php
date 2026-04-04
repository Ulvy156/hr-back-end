<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows admin to read attendance audit logs with audit metadata', function () {
    $adminUser = createAuditUser('admin');
    $employeeUser = createAuditUser('employee');

    Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $adminUser->id,
        'created_by' => $adminUser->id,
        'updated_by' => $adminUser->id,
        'corrected_by' => $adminUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 0),
        'check_out' => now()->setTime(17, 0),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'status' => 'corrected',
        'source' => 'correction',
        'correction_status' => 'approved',
        'correction_reason' => 'Adjusted by admin audit review.',
    ]);

    Passport::actingAs($adminUser);

    $this->getJson('/api/attendance/audit/logs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.audit.createdBy.id', $adminUser->id)
        ->assertJsonPath('data.0.audit.correctedBy.id', $adminUser->id)
        ->assertJsonPath('data.0.employee.id', $employeeUser->employee->id);
});

it('keeps admin read only for attendance operations by default', function () {
    $adminUser = createAuditUser('admin');
    $employeeUser = createAuditUser('employee');

    Passport::actingAs($adminUser);

    $this->postJson('/api/attendance/check-in')
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');

    $this->postJson('/api/attendance/manual', [
        'employee_id' => $employeeUser->employee->id,
        'attendance_date' => now()->toDateString(),
        'check_in_time' => now()->setTime(8, 0)->toIso8601String(),
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');
});

function createAuditUser(string $role): User
{
    $department = Department::query()->create([
        'name' => fake()->unique()->company(),
    ]);

    $position = Position::query()->create([
        'title' => fake()->unique()->jobTitle(),
    ]);

    $user = User::factory()->create();

    Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->unique()->safeEmail(),
        'phone' => fake()->numerify('##########'),
        'hire_date' => now()->subYear()->toDateString(),
        'status' => 'active',
    ]);

    $roleModel = Role::query()->firstOrCreate(
        ['name' => $role],
        ['description' => ucfirst($role)]
    );

    $user->roles()->syncWithoutDetaching([$roleModel->id]);

    return $user->fresh('employee', 'roles');
}
