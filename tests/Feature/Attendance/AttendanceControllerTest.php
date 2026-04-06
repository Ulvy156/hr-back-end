<?php

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
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
    config()->set('attendance.break_start_time', '12:00:00');
    config()->set('attendance.break_end_time', '13:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('uses Phnom Penh application timezone for attendance operations', function () {
    expect(config('app.timezone'))->toBe('Asia/Phnom_Penh')
        ->and(date_default_timezone_get())->toBe('Asia/Phnom_Penh');
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
        ->assertJsonPath('data.workedMinutes', 480)
        ->assertJsonPath('data.overtimeMinutes', 0);

    $this->getJson('/api/attendance/me/today')
        ->assertOk()
        ->assertJsonPath('data.todayAttendanceStatus', 'checked_out')
        ->assertJsonPath('data.nextAction', 'none')
        ->assertJsonPath('data.workedMinutes', 480)
        ->assertJsonPath('data.overtimeMinutes', 0);
});

it('stores whole minutes when check-out timestamps include seconds', function () {
    $employeeUser = createAttendanceUser('employee');

    Passport::actingAs($employeeUser);

    Carbon::setTestNow(now()->startOfDay()->setTime(8, 0, 0));

    $this->postJson('/api/attendance/check-in')
        ->assertCreated()
        ->assertJsonPath('data.status', 'checked_in');

    Carbon::setTestNow(now()->startOfDay()->setTime(16, 52, 20));

    $response = $this->postJson('/api/attendance/check-out');

    $response
        ->assertOk()
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.workedMinutes', 472)
        ->assertJsonPath('data.earlyLeaveMinutes', 7)
        ->assertJsonPath('data.overtimeMinutes', 0);

    $attendance = Attendance::query()
        ->where('employee_id', $employeeUser->employee->id)
        ->whereDate('attendance_date', now()->toDateString())
        ->firstOrFail();

    expect($attendance->worked_minutes)->toBe(472)
        ->and($attendance->early_leave_minutes)->toBe(7)
        ->and($attendance->overtime_minutes)->toBe(0);
});

it('lets the scan endpoint auto check in then check out for authenticated employee users', function () {
    $employeeUser = createAttendanceUser('employee');

    Passport::actingAs($employeeUser);

    Carbon::setTestNow(now()->startOfDay()->setTime(8, 0));

    $this->postJson('/api/attendance/scan')
        ->assertCreated()
        ->assertJsonPath('message', 'Check-in recorded successfully.')
        ->assertJsonPath('data.status', 'checked_in')
        ->assertJsonPath('data.source', 'scan');

    Carbon::setTestNow(now()->startOfDay()->setTime(17, 0));

    $this->postJson('/api/attendance/scan')
        ->assertOk()
        ->assertJsonPath('message', 'Check-out recorded successfully.')
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.source', 'scan')
        ->assertJsonPath('data.workedMinutes', 480);
});

it('rejects scan when today attendance is already completed', function () {
    $employeeUser = createAttendanceUser('employee');

    Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $employeeUser->id,
        'created_by' => $employeeUser->id,
        'updated_by' => $employeeUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 0),
        'check_out' => now()->setTime(17, 0),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => 'present',
        'source' => 'scan',
        'correction_status' => 'none',
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/scan')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attendance'])
        ->assertJsonPath('errors.attendance.0', 'You have already completed attendance for today.');
});

it('offsets late minutes with overtime when an employee checks out after the scheduled end time', function () {
    $employeeUser = createAttendanceUser('employee');

    Passport::actingAs($employeeUser);

    Carbon::setTestNow(now()->startOfDay()->setTime(8, 15));

    $this->postJson('/api/attendance/check-in')
        ->assertCreated()
        ->assertJsonPath('data.status', 'checked_in')
        ->assertJsonPath('data.lateMinutes', 15);

    Carbon::setTestNow(now()->startOfDay()->setTime(17, 15));

    $this->postJson('/api/attendance/check-out')
        ->assertOk()
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.lateMinutes', 0)
        ->assertJsonPath('data.overtimeMinutes', 15)
        ->assertJsonPath('data.workedMinutes', 480);

    $this->getJson('/api/attendance/me/today')
        ->assertOk()
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.lateMinutes', 0)
        ->assertJsonPath('data.overtimeMinutes', 15)
        ->assertJsonPath('data.earlyLeaveMinutes', 0);
});

it('allows hr to use self-service attendance endpoints for their own attendance', function () {
    $hrUser = createAttendanceUser('hr');

    Passport::actingAs($hrUser);

    Carbon::setTestNow(now()->startOfDay()->setTime(8, 10));

    $this->postJson('/api/attendance/check-in')
        ->assertCreated()
        ->assertJsonPath('message', 'Check-in recorded successfully.')
        ->assertJsonPath('data.employeeId', $hrUser->employee->id)
        ->assertJsonPath('data.status', 'checked_in');

    $this->getJson('/api/attendance/me/today')
        ->assertOk()
        ->assertJsonPath('data.todayAttendanceStatus', 'checked_in')
        ->assertJsonPath('data.nextAction', 'check_out');
});

it('blocks self-service check in when the employee is on approved leave for today', function () {
    $employeeUser = createAttendanceUser('employee');

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'start_date' => now()->toDateString(),
        'end_date' => now()->toDateString(),
        'manager_approved_by' => $employeeUser->employee->id,
        'manager_approved_at' => now()->subDay(),
        'hr_approved_by' => $employeeUser->employee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => 'hr_approved',
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/check-in')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attendance'])
        ->assertJsonPath('errors.attendance.0', 'You cannot record attendance while on approved leave.');
});

it('blocks self-service check out when the employee is on approved leave for today', function () {
    $employeeUser = createAttendanceUser('employee');

    Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $employeeUser->id,
        'created_by' => $employeeUser->id,
        'updated_by' => $employeeUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 0),
        'check_out' => null,
        'worked_minutes' => 0,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => 'checked_in',
        'source' => 'self_service',
        'correction_status' => 'none',
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'start_date' => now()->toDateString(),
        'end_date' => now()->toDateString(),
        'manager_approved_by' => $employeeUser->employee->id,
        'manager_approved_at' => now()->subDay(),
        'hr_approved_by' => $employeeUser->employee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => 'hr_approved',
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/check-out')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['attendance'])
        ->assertJsonPath('errors.attendance.0', 'You cannot record attendance while on approved leave.');
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
        'request_date' => now()->subDay()->toDateString(),
        'requested_check_in_time' => '08:00',
        'requested_check_out_time' => '17:00',
        'reason' => 'Scanner was delayed during entry.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Attendance correction request submitted successfully.')
        ->assertJsonPath('data.attendanceId', $attendance->id)
        ->assertJsonPath('data.requestDate', now()->subDay()->toDateString())
        ->assertJsonPath('data.requestedCheckInTime', '08:00')
        ->assertJsonPath('data.requestedCheckOutTime', '17:00')
        ->assertJsonPath('data.status', 'pending');

    expect(AttendanceCorrectionRequest::query()->count())->toBe(1)
        ->and($attendance->fresh()?->correction_status)->toBe('pending');
});

it('allows an employee to submit a missing attendance request for a date with no attendance record', function () {
    $employeeUser = createAttendanceUser('employee');

    Passport::actingAs($employeeUser);

    $response = $this->postJson('/api/attendance/me/missing-request', [
        'request_date' => now()->subDay()->toDateString(),
        'requested_check_in_time' => '08:00',
        'requested_check_out_time' => '17:00',
        'reason' => 'I forgot to check in and check out that day.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Missing attendance request submitted successfully.')
        ->assertJsonPath('data.attendanceId', null)
        ->assertJsonPath('data.requestDate', now()->subDay()->toDateString())
        ->assertJsonPath('data.attendanceDate', now()->subDay()->toDateString())
        ->assertJsonPath('data.requestedCheckInTime', '08:00')
        ->assertJsonPath('data.requestedCheckOutTime', '17:00')
        ->assertJsonPath('data.status', 'pending');

    expect(AttendanceCorrectionRequest::query()->count())->toBe(1)
        ->and(AttendanceCorrectionRequest::query()->first()?->attendance_id)->toBeNull();
});

it('rejects a duplicate missing attendance request for the same date unless the previous one was rejected', function () {
    $employeeUser = createAttendanceUser('employee');

    AttendanceCorrectionRequest::query()->create([
        'attendance_id' => null,
        'employee_id' => $employeeUser->employee->id,
        'requested_check_in_time' => now()->subDay()->setTime(8, 0),
        'requested_check_out_time' => now()->subDay()->setTime(17, 0),
        'reason' => 'Original missing request.',
        'status' => 'approved',
        'reviewed_by' => $employeeUser->id,
        'reviewed_at' => now(),
        'review_note' => 'Already handled.',
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/me/missing-request', [
        'request_date' => now()->subDay()->toDateString(),
        'requested_check_in_time' => '08:10',
        'requested_check_out_time' => '17:10',
        'reason' => 'Trying to submit another missing request.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request_date'])
        ->assertJsonPath(
            'errors.request_date.0',
            'A missing attendance request for this date already exists and can only be submitted again after rejection.'
        );
});

it('rejects a new correction request when the attendance record was already approved', function () {
    $employeeUser = createAttendanceUser('employee');

    $attendance = Attendance::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'edited_by' => $employeeUser->id,
        'created_by' => $employeeUser->id,
        'updated_by' => $employeeUser->id,
        'corrected_by' => $employeeUser->id,
        'attendance_date' => now()->subDay()->toDateString(),
        'check_in' => now()->subDay()->setTime(8, 0),
        'check_out' => now()->subDay()->setTime(17, 0),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => 'corrected',
        'source' => 'correction',
        'correction_status' => 'approved',
        'correction_reason' => 'Approved previously.',
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/me/correction-request', [
        'request_date' => now()->subDay()->toDateString(),
        'requested_check_in_time' => '08:05',
        'requested_check_out_time' => '17:00',
        'reason' => 'Trying to submit another request after approval.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request_date'])
        ->assertJsonPath(
            'errors.request_date.0',
            'This attendance record has already been corrected and approved. A new correction request is only allowed after rejection.'
        );
});

it('allows a new correction request when the previous request for the attendance was rejected', function () {
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
        'overtime_minutes' => 0,
        'status' => 'late',
        'source' => 'self_service',
        'correction_status' => 'rejected',
        'correction_reason' => 'Previously rejected request.',
    ]);

    AttendanceCorrectionRequest::query()->create([
        'attendance_id' => $attendance->id,
        'employee_id' => $employeeUser->employee->id,
        'requested_check_in_time' => now()->subDay()->setTime(8, 0),
        'requested_check_out_time' => now()->subDay()->setTime(17, 0),
        'reason' => 'Original request.',
        'status' => 'rejected',
        'reviewed_by' => $employeeUser->id,
        'reviewed_at' => now(),
        'review_note' => 'Rejected previously.',
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/me/correction-request', [
        'request_date' => now()->subDay()->toDateString(),
        'requested_check_in_time' => '08:10',
        'requested_check_out_time' => '17:10',
        'reason' => 'Submitting a new request after rejection.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.attendanceId', $attendance->id)
        ->assertJsonPath('data.requestDate', now()->subDay()->toDateString())
        ->assertJsonPath('data.status', 'pending');

    expect($attendance->fresh()?->correction_status)->toBe('pending')
        ->and(AttendanceCorrectionRequest::query()->where('attendance_id', $attendance->id)->count())->toBe(2);
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

it('filters checked_in attendance by derived open-check-in state instead of literal stored status only', function () {
    $hrUser = createAttendanceUser('hr');
    $employeeA = createAttendanceUser('employee');
    $employeeB = createAttendanceUser('employee');

    Attendance::query()->create([
        'employee_id' => $employeeA->employee->id,
        'edited_by' => $hrUser->id,
        'created_by' => $hrUser->id,
        'updated_by' => $hrUser->id,
        'corrected_by' => $hrUser->id,
        'attendance_date' => '2026-04-01',
        'check_in' => Carbon::parse('2026-04-01 08:10:00'),
        'check_out' => null,
        'worked_minutes' => 0,
        'late_minutes' => 10,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => 'corrected',
        'source' => 'correction',
        'correction_status' => 'approved',
    ]);

    Attendance::query()->create([
        'employee_id' => $employeeB->employee->id,
        'edited_by' => $hrUser->id,
        'created_by' => $hrUser->id,
        'updated_by' => $hrUser->id,
        'attendance_date' => '2026-04-01',
        'check_in' => Carbon::parse('2026-04-01 08:00:00'),
        'check_out' => Carbon::parse('2026-04-01 17:00:00'),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => 'present',
        'source' => 'manual',
        'correction_status' => 'none',
    ]);

    Passport::actingAs($hrUser);

    $this->getJson('/api/attendance?status=checked_in&from_date=2026-04-01&to_date=2026-04-02&page=1&per_page=20')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employeeId', $employeeA->employee->id)
        ->assertJsonPath('data.0.checkOutTime', null);
});

it('uses net late minutes for manual attendance when checkout overtime covers the late arrival', function () {
    $hrUser = createAttendanceUser('hr');
    $employeeUser = createAttendanceUser('employee');

    Passport::actingAs($hrUser);

    $response = $this->postJson('/api/attendance/manual', [
        'employee_id' => $employeeUser->employee->id,
        'attendance_date' => now()->toDateString(),
        'check_in_time' => now()->setTime(8, 15)->toIso8601String(),
        'check_out_time' => now()->setTime(17, 15)->toIso8601String(),
        'notes' => 'Stayed late to complete the full shift.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'present')
        ->assertJsonPath('data.lateMinutes', 0)
        ->assertJsonPath('data.overtimeMinutes', 15)
        ->assertJsonPath('data.earlyLeaveMinutes', 0)
        ->assertJsonPath('data.workedMinutes', 480);
});

it('previews outage recovery candidates with search, department filter, and pagination', function () {
    $hrUser = createAttendanceUser('hr');
    $selectedDepartment = Department::query()->create(['name' => 'Selected Team']);
    $otherDepartment = Department::query()->create(['name' => 'Other Team']);
    $selectedEmployee = createAttendanceUser('employee', $selectedDepartment);
    $otherSelectedEmployee = createAttendanceUser('employee', $otherDepartment);
    $onLeaveEmployee = createAttendanceUser('employee');
    $existingAttendanceEmployee = createAttendanceUser('employee');
    createAttendanceUser('employee', null, 'inactive');

    $selectedEmployee->employee->forceFill([
        'first_name' => 'Target',
        'last_name' => 'User',
        'employee_code' => 'EMP-TARGET',
    ])->save();

    $otherSelectedEmployee->employee->forceFill([
        'first_name' => 'Other',
        'last_name' => 'Visible',
        'employee_code' => 'EMP-OTHER',
    ])->save();

    LeaveRequest::query()->create([
        'employee_id' => $onLeaveEmployee->employee->id,
        'type' => 'annual',
        'start_date' => '2026-04-06',
        'end_date' => '2026-04-06',
        'manager_approved_by' => $hrUser->employee->id,
        'manager_approved_at' => Carbon::parse('2026-04-05 10:00:00'),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => Carbon::parse('2026-04-05 11:00:00'),
        'status' => 'hr_approved',
    ]);

    Attendance::query()->create([
        'employee_id' => $existingAttendanceEmployee->employee->id,
        'edited_by' => $hrUser->id,
        'created_by' => $hrUser->id,
        'updated_by' => $hrUser->id,
        'attendance_date' => '2026-04-06',
        'check_in' => Carbon::parse('2026-04-06 08:00:00'),
        'check_out' => Carbon::parse('2026-04-06 17:00:00'),
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 0,
        'status' => 'present',
        'source' => 'manual',
        'correction_status' => 'none',
    ]);

    Passport::actingAs($hrUser);

    $this->getJson('/api/attendance/outage-recovery/preview?date=2026-04-06&search=target&department_id='.$selectedDepartment->id.'&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.date', '2026-04-06')
        ->assertJsonPath('data.defaults.notes', 'System outage recovery')
        ->assertJsonPath('data.selectedEmployees.total', 1)
        ->assertJsonPath('data.selectedEmployees.per_page', 1)
        ->assertJsonPath('data.selectedEmployees.data.0.id', $selectedEmployee->employee->id)
        ->assertJsonPath('data.skipped.counts.onLeave', 0)
        ->assertJsonPath('data.skipped.counts.existingAttendance', 0)
        ->assertJsonMissing(['id' => $otherSelectedEmployee->employee->id]);
});

it('allows hr to apply outage recovery attendance for the confirmed employee list only', function () {
    $hrUser = createAttendanceUser('hr');
    $selectedEmployee = createAttendanceUser('employee');
    $absentEmployee = createAttendanceUser('employee');

    Passport::actingAs($hrUser);

    $this->postJson('/api/attendance/outage-recovery/apply', [
        'date' => '2026-04-05',
        'employee_ids' => [$selectedEmployee->employee->id],
        'notes' => 'System outage recovery',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Outage recovery attendance created successfully.')
        ->assertJsonPath('data.date', '2026-04-05')
        ->assertJsonPath('data.createdCount', 1)
        ->assertJsonPath('data.employees.0.id', $selectedEmployee->employee->id);

    $selectedAttendance = Attendance::query()
        ->where('employee_id', $selectedEmployee->employee->id)
        ->whereDate('attendance_date', '2026-04-05')
        ->first();

    $absentAttendance = Attendance::query()
        ->where('employee_id', $absentEmployee->employee->id)
        ->whereDate('attendance_date', '2026-04-05')
        ->first();

    expect($selectedAttendance)->not->toBeNull()
        ->and($selectedAttendance?->source)->toBe('manual')
        ->and($selectedAttendance?->notes)->toBe('System outage recovery')
        ->and($selectedAttendance?->status)->toBe('present')
        ->and($absentAttendance)->toBeNull();
});

it('allows hr to create open outage recovery attendance that the employee can check out later', function () {
    $hrUser = createAttendanceUser('hr');
    $employeeUser = createAttendanceUser('employee');

    Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00'));

    Passport::actingAs($hrUser);

    $this->postJson('/api/attendance/outage-recovery/apply', [
        'date' => '2026-04-06',
        'employee_ids' => [$employeeUser->employee->id],
        'check_in_time' => '2026-04-06T09:15:00+07:00',
        'check_out_time' => null,
        'notes' => 'System outage recovery',
    ])
        ->assertOk()
        ->assertJsonPath('data.createdCount', 1);

    $recoveredAttendance = Attendance::query()
        ->where('employee_id', $employeeUser->employee->id)
        ->whereDate('attendance_date', '2026-04-06')
        ->first();

    expect($recoveredAttendance)->not->toBeNull()
        ->and($recoveredAttendance?->check_in?->format('H:i:s'))->toBe('09:15:00')
        ->and($recoveredAttendance?->check_out)->toBeNull()
        ->and($recoveredAttendance?->status)->toBe('checked_in');

    Carbon::setTestNow(Carbon::parse('2026-04-06 17:45:00'));

    Passport::actingAs($employeeUser);

    $this->postJson('/api/attendance/check-out')
        ->assertOk()
        ->assertJsonPath('message', 'Check-out recorded successfully.')
        ->assertJsonPath('data.workedMinutes', 450)
        ->assertJsonPath('data.overtimeMinutes', 45)
        ->assertJsonPath('data.source', 'self_service');

    expect($recoveredAttendance->fresh()?->check_out?->format('H:i:s'))->toBe('17:45:00')
        ->and($recoveredAttendance->fresh()?->status)->not->toBe('checked_in');
});

it('rejects outage recovery apply when check-in or check-out time is in the future', function () {
    $hrUser = createAttendanceUser('hr');
    $selectedEmployee = createAttendanceUser('employee');

    Carbon::setTestNow(Carbon::parse('2026-04-06 09:00:00'));

    Passport::actingAs($hrUser);

    $this->postJson('/api/attendance/outage-recovery/apply', [
        'date' => '2026-04-06',
        'employee_ids' => [$selectedEmployee->employee->id],
        'check_in_time' => '2026-04-06T08:00:00+07:00',
        'check_out_time' => '2026-04-06T17:00:00+07:00',
        'notes' => 'System outage recovery',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_out_time'])
        ->assertJsonPath('errors.check_out_time.0', 'Check-out time cannot be in the future.');
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

it('allows hr to approve a missing attendance request and creates the attendance record', function () {
    $hrUser = createAttendanceUser('hr');
    $employeeUser = createAttendanceUser('employee');

    $correctionRequest = AttendanceCorrectionRequest::query()->create([
        'attendance_id' => null,
        'employee_id' => $employeeUser->employee->id,
        'requested_check_in_time' => now()->subDay()->setTime(8, 5),
        'requested_check_out_time' => now()->subDay()->setTime(17, 5),
        'reason' => 'Forgot both check-in and check-out.',
        'status' => 'pending',
    ]);

    Passport::actingAs($hrUser);

    $response = $this->patchJson("/api/attendance/correction-requests/{$correctionRequest->id}", [
        'status' => 'approved',
        'review_note' => 'Approved missing attendance request.',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Attendance correction request reviewed successfully.')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.attendanceId', fn (mixed $value): bool => is_int($value))
        ->assertJsonPath('data.attendance.status', 'corrected');

    $attendance = Attendance::query()
        ->where('employee_id', $employeeUser->employee->id)
        ->whereDate('attendance_date', now()->subDay()->toDateString())
        ->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance?->status)->toBe('corrected')
        ->and($attendance?->source)->toBe('correction')
        ->and($attendance?->corrected_by)->toBe($hrUser->id)
        ->and($correctionRequest->fresh()?->attendance_id)->toBe($attendance?->id);
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
