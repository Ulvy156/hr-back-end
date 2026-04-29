<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('submits an overtime request for self and calculates minutes and type on the backend', function () {
    [$employeeUser] = createOvertimeActors();

    PublicHoliday::query()->create([
        'name' => 'Khmer New Year',
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
        'is_paid' => true,
        'source' => 'test',
        'metadata' => [],
    ]);

    createOvertimeAttendance(
        $employeeUser->employee,
        '2026-04-15',
        '18:00:00',
        '20:30:00',
        150,
        150,
    );

    Passport::actingAs($employeeUser);

    $response = $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-15',
        'start_time' => '18:00',
        'end_time' => '20:30',
        'reason' => 'Month-end reconciliation support.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Overtime request submitted successfully.')
        ->assertJsonPath('data.employee_id', $employeeUser->employee->id)
        ->assertJsonPath('data.overtime_date', '2026-04-15')
        ->assertJsonPath('data.start_time', '18:00:00')
        ->assertJsonPath('data.end_time', '20:30:00')
        ->assertJsonPath('data.minutes', 150)
        ->assertJsonPath('data.hours', 2.5)
        ->assertJsonPath('data.overtime_type', OvertimeType::Holiday)
        ->assertJsonPath('data.status', OvertimeRequestStatus::Pending)
        ->assertJsonPath('data.approval_stage', OvertimeApprovalStage::ManagerReview)
        ->assertJsonPath('data.cancelable', true);

    $overtimeRequest = OvertimeRequest::query()->firstOrFail();

    expect($overtimeRequest->employee_id)->toBe($employeeUser->employee->id)
        ->and($overtimeRequest->minutes)->toBe(150)
        ->and($overtimeRequest->overtime_type)->toBe(OvertimeType::Holiday);

    $activity = Activity::query()
        ->where('log_name', 'overtime')
        ->where('event', 'overtime_request_created')
        ->where('subject_type', OvertimeRequest::class)
        ->where('subject_id', $overtimeRequest->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($employeeUser->id)
        ->and($activity?->getExtraProperty('minutes'))->toBe(150)
        ->and($activity?->getExtraProperty('overtime_type'))->toBe(OvertimeType::Holiday);
});

it('rejects overtime requests with invalid time ranges or trusted backend fields', function () {
    [$employeeUser] = createOvertimeActors();

    Passport::actingAs($employeeUser);

    $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-16',
        'start_time' => '20:00',
        'end_time' => '19:00',
        'reason' => 'Late support.',
        'minutes' => 999,
        'overtime_type' => OvertimeType::Weekend,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.end_time.0', 'The end time field must be a date after start time.')
        ->assertJsonPath('errors.minutes.0', 'The minutes field is prohibited.')
        ->assertJsonPath('errors.overtime_type.0', 'The overtime type field is prohibited.');
});

it('blocks duplicate pending overtime requests for the same employee and time range', function () {
    [$employeeUser] = createOvertimeActors();

    OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-16',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Existing overtime.',
        'status' => OvertimeRequestStatus::Pending,
        'approval_stage' => OvertimeApprovalStage::ManagerReview,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-16',
        'start_time' => '19:00',
        'end_time' => '21:00',
        'reason' => 'Overlapping overtime.',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.start_time.0', 'An overlapping pending or approved overtime request already exists for this employee and time range.');
});

it('allows employees to submit today overtime requests without an attendance record', function () {
    test()->travelTo(Carbon::parse('2026-04-22 09:00:00'));

    [$employeeUser] = createOvertimeActors();

    Passport::actingAs($employeeUser);

    $response = $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-22',
        'start_time' => '18:00',
        'end_time' => '20:00',
        'reason' => 'Manual overtime request for today.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Overtime request submitted successfully.')
        ->assertJsonPath('data.employee_id', $employeeUser->employee->id)
        ->assertJsonPath('data.overtime_date', '2026-04-22')
        ->assertJsonPath('data.start_time', '18:00:00')
        ->assertJsonPath('data.end_time', '20:00:00')
        ->assertJsonPath('data.minutes', 120)
        ->assertJsonPath('data.status', OvertimeRequestStatus::Pending)
        ->assertJsonPath('data.approval_stage', OvertimeApprovalStage::ManagerReview);

    test()->travelBack();
});

it('enforces the 10 hour daily overtime cap across active requests', function () {
    [$employeeUser, $managerUser] = createOvertimeActors();

    createOvertimeAttendance(
        $employeeUser->employee,
        '2026-04-24',
        '08:00:00',
        '20:00:00',
        720,
        720,
    );

    OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-24',
        'start_time' => '08:00:00',
        'end_time' => '12:00:00',
        'reason' => 'Approved first block.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subHours(3),
        'minutes' => 240,
        'overtime_type' => OvertimeType::Weekend,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-24',
        'start_time' => '12:30',
        'end_time' => '18:45',
        'reason' => 'Would exceed the daily cap.',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.minutes.0', 'Total requested overtime for this day cannot exceed 10 hours.');
});

it('allows employees to submit future-date overtime requests when they have create permission and employee context', function () {
    test()->travelTo(Carbon::parse('2026-04-20 09:00:00'));

    [$employeeUser] = createOvertimeActors();

    Passport::actingAs($employeeUser);

    $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-21',
        'start_time' => '18:00',
        'end_time' => '20:00',
        'reason' => 'Employee future request.',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Overtime request submitted successfully.')
        ->assertJsonPath('data.employee_id', $employeeUser->employee->id)
        ->assertJsonPath('data.overtime_date', '2026-04-21')
        ->assertJsonPath('data.minutes', 120)
        ->assertJsonPath('data.status', OvertimeRequestStatus::Pending)
        ->assertJsonPath('data.approval_stage', OvertimeApprovalStage::ManagerReview);

    test()->travelBack();
});

it('does not allow an admin without employee context to create a self-service overtime request', function () {
    $adminUser = createOvertimeUserWithRole('admin', 'future.admin@example.com');
    $adminUser->givePermissionTo(PermissionName::OvertimeRequestCreate->value);

    Passport::actingAs($adminUser);

    $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-21',
        'start_time' => '18:00',
        'end_time' => '20:00',
        'reason' => 'Admin future request.',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.user.0', 'The authenticated user is not linked to an employee profile.');
});

it('does not allow users without overtime create permission to submit overtime requests', function () {
    [$employeeUser, $managerUser] = createOvertimeActors();

    $userWithoutPermission = User::factory()->create([
        'email' => 'roleless.overtime@example.com',
    ]);

    Employee::query()->create([
        'user_id' => $userWithoutPermission->id,
        'department_id' => $employeeUser->employee->department_id,
        'current_position_id' => $employeeUser->employee->current_position_id,
        'manager_id' => $managerUser->employee->id,
        'first_name' => 'Roleless',
        'last_name' => 'Overtime',
        'email' => 'roleless.overtime.employee@example.com',
        'phone' => '0123456711',
        'hire_date' => '2024-01-01',
        'employment_type' => 'full_time',
        'confirmation_date' => '2024-01-01',
        'status' => 'active',
    ]);

    Passport::actingAs($userWithoutPermission);

    $this->postJson('/api/overtime-requests', [
        'overtime_date' => '2026-04-21',
        'start_time' => '18:00',
        'end_time' => '20:00',
        'reason' => 'Unauthorized overtime request.',
    ])->assertForbidden();
});

it('enforces manager-only final approval and limits approval to direct reports', function () {
    [$employeeUser, $managerUser, $hrUser] = createOvertimeActors();
    [, $otherManagerUser] = createOvertimeActors(prefix: 'other');

    $overtimeRequest = OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-16',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Close support.',
        'status' => OvertimeRequestStatus::Pending,
        'approval_stage' => OvertimeApprovalStage::ManagerReview,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);

    Passport::actingAs($otherManagerUser);

    $this->postJson("/api/overtime-requests/{$overtimeRequest->id}/manager-approve")
        ->assertForbidden();

    $adminUser = createOvertimeUserWithRole('admin', 'admin.overtime@example.com');

    Passport::actingAs($adminUser);

    $this->postJson("/api/overtime-requests/{$overtimeRequest->id}/manager-approve")
        ->assertForbidden();

    Passport::actingAs($managerUser);

    $this->postJson("/api/overtime-requests/{$overtimeRequest->id}/manager-approve")
        ->assertOk()
        ->assertJsonPath('message', 'Overtime request approved successfully.')
        ->assertJsonPath('data.status', OvertimeRequestStatus::Approved)
        ->assertJsonPath('data.approval_stage', OvertimeApprovalStage::Completed)
        ->assertJsonPath('data.manager_approved_by.id', $managerUser->employee->id)
        ->assertJsonMissingPath('data.hr_approved_by');

    Passport::actingAs($hrUser);

    $this->postJson("/api/overtime-requests/{$overtimeRequest->id}/hr-approve")
        ->assertNotFound();
});

it('allows manager rejection for pending overtime requests only', function () {
    [$employeeUser, $managerUser] = createOvertimeActors();

    $pendingRequest = OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-17',
        'start_time' => '18:00:00',
        'end_time' => '19:30:00',
        'reason' => 'Pending overtime.',
        'status' => OvertimeRequestStatus::Pending,
        'approval_stage' => OvertimeApprovalStage::ManagerReview,
        'minutes' => 90,
        'overtime_type' => OvertimeType::Normal,
    ]);

    Passport::actingAs($managerUser);

    $this->postJson("/api/overtime-requests/{$pendingRequest->id}/reject", [
        'rejection_reason' => 'Project priority changed.',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Overtime request rejected successfully.')
        ->assertJsonPath('data.status', OvertimeRequestStatus::Rejected)
        ->assertJsonPath('data.approval_stage', OvertimeApprovalStage::Completed)
        ->assertJsonPath('data.rejection_reason', 'Project priority changed.');

    $approvedRequest = OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-18',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Already approved.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subHour(),
        'minutes' => 120,
        'overtime_type' => OvertimeType::Weekend,
    ]);

    Passport::actingAs($managerUser);

    $this->postJson("/api/overtime-requests/{$approvedRequest->id}/reject", [
        'rejection_reason' => 'Manager cannot reject approved OT.',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Only pending overtime requests can be rejected.');
});

it('allows owners to cancel pending overtime requests only', function () {
    [$employeeUser, $managerUser] = createOvertimeActors();

    $pendingRequest = OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-19',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Cancelable request.',
        'status' => OvertimeRequestStatus::Pending,
        'approval_stage' => OvertimeApprovalStage::ManagerReview,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Weekend,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson("/api/overtime-requests/{$pendingRequest->id}/cancel")
        ->assertOk()
        ->assertJsonPath('message', 'Overtime request cancelled successfully.')
        ->assertJsonPath('data.status', OvertimeRequestStatus::Cancelled)
        ->assertJsonPath('data.approval_stage', OvertimeApprovalStage::Completed)
        ->assertJsonPath('data.cancelable', false);

    $approvedRequest = OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-20',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Already approved.',
        'status' => OvertimeRequestStatus::Approved,
        'approval_stage' => OvertimeApprovalStage::Completed,
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subHours(2),
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);

    $this->postJson("/api/overtime-requests/{$approvedRequest->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Only pending overtime requests can be cancelled.');
});

it('scopes overtime request list and detail access to self assigned or permitted viewers', function () {
    [$employeeUser, $managerUser] = createOvertimeActors();
    [, $otherManagerUser] = createOvertimeActors(prefix: 'other');
    $adminUser = createOvertimeUserWithRole('admin', 'overtime.viewer.admin@example.com');

    $overtimeRequest = OvertimeRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'overtime_date' => '2026-04-21',
        'start_time' => '18:00:00',
        'end_time' => '20:00:00',
        'reason' => 'Visibility test.',
        'status' => OvertimeRequestStatus::Pending,
        'approval_stage' => OvertimeApprovalStage::ManagerReview,
        'minutes' => 120,
        'overtime_type' => OvertimeType::Normal,
    ]);

    Passport::actingAs($employeeUser);

    $this->getJson('/api/overtime-requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $overtimeRequest->id)
        ->assertJsonPath('data.0.employee_id', $employeeUser->employee->id);

    $this->getJson("/api/overtime-requests/{$overtimeRequest->id}")
        ->assertOk()
        ->assertJsonPath('id', $overtimeRequest->id)
        ->assertJsonPath('employee_id', $employeeUser->employee->id);

    Passport::actingAs($managerUser);

    $this->getJson('/api/overtime-requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $overtimeRequest->id);

    Passport::actingAs($adminUser);

    $this->getJson('/api/overtime-requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $overtimeRequest->id);

    $this->getJson("/api/overtime-requests/{$overtimeRequest->id}")
        ->assertOk()
        ->assertJsonPath('id', $overtimeRequest->id);

    Passport::actingAs($otherManagerUser);

    $this->getJson("/api/overtime-requests/{$overtimeRequest->id}")
        ->assertForbidden();
});

/**
 * @return array{0: User, 1: User, 2: User}
 */
function createOvertimeActors(string $prefix = 'default'): array
{
    $department = Department::query()->create([
        'name' => 'Operations '.$prefix,
    ]);
    $hrDepartment = Department::query()->create([
        'name' => 'HR '.$prefix,
    ]);
    $managerPosition = Position::query()->create([
        'title' => 'Manager '.$prefix,
    ]);
    $staffPosition = Position::query()->create([
        'title' => 'Staff '.$prefix,
    ]);
    $hrPosition = Position::query()->create([
        'title' => 'HR Officer '.$prefix,
    ]);

    $managerUser = createOvertimeUserWithRole('manager', "{$prefix}.manager@example.com");
    $managerEmployee = Employee::query()->create([
        'user_id' => $managerUser->id,
        'department_id' => $department->id,
        'current_position_id' => $managerPosition->id,
        'first_name' => ucfirst($prefix).'Manager',
        'last_name' => 'Leader',
        'email' => "{$prefix}.manager.employee@example.com",
        'phone' => '0123456701',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $employeeUser = createOvertimeUserWithRole('employee', "{$prefix}.employee@example.com");
    Employee::query()->create([
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'current_position_id' => $staffPosition->id,
        'manager_id' => $managerEmployee->id,
        'first_name' => ucfirst($prefix).'Employee',
        'last_name' => 'Staff',
        'email' => "{$prefix}.employee.staff@example.com",
        'phone' => '0123456702',
        'hire_date' => '2024-01-01',
        'employment_type' => 'full_time',
        'confirmation_date' => '2024-01-01',
        'status' => 'active',
    ]);

    $hrUser = createOvertimeUserWithRole('hr', "{$prefix}.hr@example.com");
    Employee::query()->create([
        'user_id' => $hrUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $hrPosition->id,
        'first_name' => ucfirst($prefix).'Hr',
        'last_name' => 'Officer',
        'email' => "{$prefix}.hr.employee@example.com",
        'phone' => '0123456703',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    return [
        $employeeUser->fresh('employee.department', 'roles.permissions'),
        $managerUser->fresh('employee.department', 'roles.permissions'),
        $hrUser->fresh('employee.department', 'roles.permissions'),
    ];
}

function createOvertimeUserWithRole(string $roleName, string $email): User
{
    $user = User::factory()->create([
        'email' => $email,
    ]);

    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user;
}

function createOvertimeAttendance(
    Employee $employee,
    string $date,
    string $checkInTime,
    string $checkOutTime,
    int $workedMinutes,
    int $overtimeMinutes,
): Attendance {
    return Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => $date,
        'check_in' => "{$date} {$checkInTime}",
        'check_out' => "{$date} {$checkOutTime}",
        'worked_minutes' => $workedMinutes,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => $overtimeMinutes,
        'status' => 'present',
        'source' => 'manual',
        'correction_status' => 'none',
    ]);
}
