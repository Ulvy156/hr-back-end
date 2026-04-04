<?php

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('attendance.work_start_time', '08:00:00');
    config()->set('attendance.work_end_time', '17:00:00');
    config()->set('attendance.scan_token', null);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('allows an employee to check in and check out from self-service endpoints', function () {
    $employeeUser = createAttendanceUser('employee');

    Passport::actingAs($employeeUser);

    Carbon::setTestNow(now()->startOfDay()->setTime(8, 0));

    $checkInResponse = $this->postJson('/api/attendance/check-in');

    $checkInResponse
        ->assertCreated()
        ->assertJsonPath('message', 'Check-in recorded successfully.')
        ->assertJsonPath('data.status', 'checked_in')
        ->assertJsonPath('data.employeeId', $employeeUser->employee->id);

    Carbon::setTestNow(now()->startOfDay()->setTime(17, 0));

    $checkOutResponse = $this->postJson('/api/attendance/check-out');

    $checkOutResponse
        ->assertOk()
        ->assertJsonPath('message', 'Check-out recorded successfully.')
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.workedMinutes', 540);

    $this->getJson('/api/attendance/me/today')
        ->assertOk()
        ->assertJsonPath('data.todayAttendanceStatus', 'checked_out')
        ->assertJsonPath('data.nextAction', 'none')
        ->assertJsonPath('data.workedMinutes', 540);
});

it('keeps employee attendance history scoped to the authenticated employee', function () {
    $employeeUser = createAttendanceUser('employee');
    $otherEmployeeUser = createAttendanceUser('employee');

    Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $employeeUser->id,
        'created_by' => $employeeUser->id,
        'updated_by' => $employeeUser->id,
        'attendance_date' => now()->subDay()->toDateString(),
        'check_in' => now()->subDay()->setTime(8, 0),
        'check_out' => now()->subDay()->setTime(17, 0),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'status' => 'present',
        'source' => 'self_service',
        'correction_status' => 'none',
    ]);

    Attendance::query()->create([
        'employee_id' => $otherEmployeeUser->employee->id,
        'edited_by' => $otherEmployeeUser->id,
        'created_by' => $otherEmployeeUser->id,
        'updated_by' => $otherEmployeeUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 30),
        'check_out' => now()->setTime(17, 30),
        'worked_minutes' => 540,
        'late_minutes' => 30,
        'early_leave_minutes' => 0,
        'status' => 'late',
        'source' => 'self_service',
        'correction_status' => 'none',
    ]);

    Passport::actingAs($employeeUser);

    $this->getJson('/api/attendance/me/history')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employeeId', $employeeUser->employee->id);

    $this->getJson('/api/attendance')
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');
});

it('allows an employee to submit an attendance correction request for their own record', function () {
    $employeeUser = createAttendanceUser('employee');

    $attendance = Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $employeeUser->id,
        'created_by' => $employeeUser->id,
        'updated_by' => $employeeUser->id,
        'attendance_date' => now()->subDay()->toDateString(),
        'check_in' => now()->subDay()->setTime(9, 0),
        'check_out' => now()->subDay()->setTime(17, 0),
        'worked_minutes' => 480,
        'late_minutes' => 60,
        'early_leave_minutes' => 0,
        'status' => 'late',
        'source' => 'self_service',
        'correction_status' => 'none',
    ]);

    Passport::actingAs($employeeUser);

    $response = $this->postJson('/api/attendance/me/correction-request', [
        'attendance_id' => $attendance->id,
        'requested_check_in_time' => now()->subDay()->setTime(8, 0)->toIso8601String(),
        'requested_check_out_time' => now()->subDay()->setTime(17, 0)->toIso8601String(),
        'reason' => 'Scanner was delayed during entry.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Attendance correction request submitted successfully.')
        ->assertJsonPath('data.attendanceId', $attendance->id)
        ->assertJsonPath('data.status', 'pending');

    expect(AttendanceCorrectionRequest::query()->count())->toBe(1)
        ->and($attendance->fresh()?->correction_status)->toBe('pending');
});

it('allows hr to filter attendance and create manual attendance records', function () {
    $hrUser = createAttendanceUser('hr');
    $departmentA = Department::query()->create(['name' => 'HR Ops']);
    $departmentB = Department::query()->create(['name' => 'Finance']);
    $employeeA = createAttendanceUser('employee', $departmentA);
    $employeeB = createAttendanceUser('employee', $departmentB);

    Attendance::query()->create([
        'employee_id' => $employeeA->employee->id,
        'edited_by' => $hrUser->id,
        'created_by' => $hrUser->id,
        'updated_by' => $hrUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 0),
        'check_out' => now()->setTime(17, 0),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'status' => 'present',
        'source' => 'manual',
        'correction_status' => 'none',
    ]);

    Attendance::query()->create([
        'employee_id' => $employeeB->employee->id,
        'edited_by' => $hrUser->id,
        'created_by' => $hrUser->id,
        'updated_by' => $hrUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(9, 0),
        'check_out' => now()->setTime(17, 0),
        'worked_minutes' => 480,
        'late_minutes' => 60,
        'early_leave_minutes' => 0,
        'status' => 'late',
        'source' => 'manual',
        'correction_status' => 'none',
    ]);

    Passport::actingAs($hrUser);

    $this->getJson('/api/attendance?department_id='.$departmentA->id.'&status=present')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee.department', 'HR Ops');

    $manualCreateResponse = $this->postJson('/api/attendance/manual', [
        'employee_id' => $employeeB->employee->id,
        'attendance_date' => now()->subDay()->toDateString(),
        'check_in_time' => now()->subDay()->setTime(8, 15)->toIso8601String(),
        'check_out_time' => now()->subDay()->setTime(17, 0)->toIso8601String(),
        'notes' => 'Created by HR for late kiosk sync.',
    ]);

    $manualCreateResponse
        ->assertCreated()
        ->assertJsonPath('message', 'Attendance created successfully.')
        ->assertJsonPath('data.source', 'manual');

    $this->getJson('/api/attendance/summary/today')
        ->assertOk()
        ->assertJsonPath('data.totals.checkedInTodayCount', 2)
        ->assertJsonPath('data.totals.checkedOutTodayCount', 2)
        ->assertJsonPath('data.totals.lateCountToday', 1);
});

it('allows hr to review correction requests and updates the linked attendance record', function () {
    $hrUser = createAttendanceUser('hr');
    $employeeUser = createAttendanceUser('employee');

    $attendance = Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $employeeUser->id,
        'created_by' => $employeeUser->id,
        'updated_by' => $employeeUser->id,
        'attendance_date' => now()->subDay()->toDateString(),
        'check_in' => now()->subDay()->setTime(9, 0),
        'check_out' => now()->subDay()->setTime(17, 0),
        'worked_minutes' => 480,
        'late_minutes' => 60,
        'early_leave_minutes' => 0,
        'status' => 'late',
        'source' => 'self_service',
        'correction_status' => 'pending',
    ]);

    $correctionRequest = AttendanceCorrectionRequest::query()->create([
        'attendance_id' => $attendance->id,
        'employee_id' => $employeeUser->employee->id,
        'requested_check_in_time' => now()->subDay()->setTime(8, 0),
        'requested_check_out_time' => now()->subDay()->setTime(17, 0),
        'reason' => 'Badge scanner failed at the gate.',
        'status' => 'pending',
    ]);

    Passport::actingAs($hrUser);

    $response = $this->patchJson("/api/attendance/correction-requests/{$correctionRequest->id}", [
        'status' => 'approved',
        'review_note' => 'Approved after HR review.',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Attendance correction request reviewed successfully.')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.reviewedBy.id', $hrUser->id);

    expect($attendance->fresh()?->status)->toBe('corrected')
        ->and($attendance->fresh()?->correction_status)->toBe('approved')
        ->and($attendance->fresh()?->corrected_by)->toBe($hrUser->id)
        ->and($attendance->fresh()?->late_minutes)->toBe(0);
});

function createAttendanceUser(
    string $role = 'employee',
    ?Department $department = null,
    string $employeeStatus = 'active',
): User {
    $department ??= Department::query()->create([
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
        'status' => $employeeStatus,
    ]);

    $roleModel = Role::query()->firstOrCreate(
        ['name' => $role],
        ['description' => ucfirst($role)]
    );

    $user->roles()->syncWithoutDetaching([$roleModel->id]);

    return $user->fresh('employee.department', 'roles');
}
