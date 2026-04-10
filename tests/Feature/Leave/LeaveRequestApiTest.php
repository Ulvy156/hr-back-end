<?php

use App\EmployeeGender;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveRequestStatus;
use Carbon\Carbon;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LeaveTypeSeeder::class);
});

it('submits a leave request, excludes policy dates, and logs the action', function () {
    [$employeeUser] = createLeaveActors();

    PublicHoliday::query()->create([
        'name' => 'Khmer New Year Eve',
        'holiday_date' => '2026-04-15',
        'year' => 2026,
        'country_code' => 'KH',
        'is_paid' => true,
        'source' => 'test',
        'metadata' => [],
    ]);

    Passport::actingAs($employeeUser);

    $response = $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Family travel plans.',
        'start_date' => '2026-04-15',
        'end_date' => '2026-04-19',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Leave request submitted successfully.')
        ->assertJsonPath('data.type', 'annual')
        ->assertJsonPath('data.leave_type_label', 'Annual Leave')
        ->assertJsonPath('data.reason', 'Family travel plans.')
        ->assertJsonPath('data.duration_type', 'full_day')
        ->assertJsonPath('data.half_day_session', null)
        ->assertJsonPath('data.status', LeaveRequestStatus::Pending)
        ->assertJsonPath('data.status_label', 'Pending')
        ->assertJsonPath('data.requested_days', 2)
        ->assertJsonPath('data.total_days', 2)
        ->assertJsonPath('data.cancelable', true)
        ->assertJsonPath('data.approval_stage', 'manager_review')
        ->assertJsonPath('data.manager_approval_status', 'pending')
        ->assertJsonPath('data.hr_approval_status', 'pending')
        ->assertJsonPath('data.approval_progress.manager.status', 'pending')
        ->assertJsonPath('data.approval_progress.hr.status', 'pending')
        ->assertJsonPath('data.employee.id', $employeeUser->employee->id)
        ->assertJsonPath('data.balances.0.year', 2026)
        ->assertJsonPath('data.submitted_at', fn (mixed $value) => is_string($value) && $value !== '');

    $leaveRequest = LeaveRequest::query()->firstOrFail();

    expect($leaveRequest->employee_id)->toBe($employeeUser->employee->id)
        ->and($leaveRequest->status)->toBe(LeaveRequestStatus::Pending)
        ->and($leaveRequest->reason)->toBe('Family travel plans.');

    /** @var Activity|null $activity */
    $activity = Activity::query()
        ->where('log_name', 'leave')
        ->where('event', 'leave_request_created')
        ->where('subject_type', LeaveRequest::class)
        ->where('subject_id', $leaveRequest->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($employeeUser->id)
        ->and($activity?->getExtraProperty('requested_days'))->toBe(2)
        ->and($activity?->getExtraProperty('reason'))->toBe('Family travel plans.');
});

it('blocks annual leave requests for non-full-time employees', function () {
    [$employeeUser] = createLeaveActors(
        hireDate: '2026-01-15',
        employeeEmploymentType: 'contract',
    );

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Personal leave.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-21',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'Only full-time employees can request this leave type. Please use unpaid leave instead.');
});

it('rejects annual leave for probation employees and allows unpaid leave', function () {
    [$employeeUser] = createLeaveActors(
        hireDate: '2026-03-01',
        employeeEmploymentType: 'probation',
    );

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Need annual leave.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'Employees on probation cannot request annual leave. Please use unpaid leave instead.');

    $this->postJson('/api/leave/requests', [
        'type' => 'unpaid',
        'reason' => 'Personal emergency.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'unpaid')
        ->assertJsonPath('data.status', LeaveRequestStatus::Pending);
});

it('restricts non-unpaid leave types to full-time employees only', function () {
    [$employeeUser] = createLeaveActors(
        hireDate: '2026-03-01',
        employeeEmploymentType: 'contract',
    );

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'sick',
        'reason' => 'Medical leave.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.type.0', 'Only full-time employees can request this leave type. Please use unpaid leave instead.');

    $this->postJson('/api/leave/requests', [
        'type' => 'unpaid',
        'reason' => 'Personal matter.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'unpaid');
});

it('allows annual leave immediately for full-time employees without a one-year wait', function () {
    [$employeeUser] = createLeaveActors(
        hireDate: '2026-04-01',
        employeeEmploymentType: 'full_time',
        today: '2026-04-10',
    );

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Family plans.',
        'start_date' => '2026-04-14',
        'end_date' => '2026-04-14',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'annual')
        ->assertJsonPath('data.status', LeaveRequestStatus::Pending);
});

it('allows full-time employees to request annual leave using full remaining balance without accrual or pending deductions', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveActors(
        hireDate: '2026-04-01',
        employeeEmploymentType: 'full_time',
        today: '2026-04-10',
    );

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Approved annual leave.',
        'start_date' => '2026-04-14',
        'end_date' => '2026-04-15',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDays(2),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Pending annual leave.',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-15',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Family travel plans.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-24',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'annual')
        ->assertJsonPath('data.requested_days', 5);
});

it('rejects full-time annual leave requests when request days exceed remaining days based on approved leave only', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveActors(
        hireDate: '2026-04-01',
        employeeEmploymentType: 'full_time',
        today: '2026-04-10',
    );

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Approved annual leave.',
        'start_date' => '2026-01-05',
        'end_date' => '2026-01-22',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDays(20),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subDays(19),
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Family travel plans.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-24',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.end_date.0', 'The selected leave duration exceeds the available balance for this leave type.');
});

it('enforces annual leave notice rules and allows same-day sick leave', function () {
    [$employeeUser] = createLeaveActors(today: '2026-04-10');

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Short vacation.',
        'start_date' => '2026-04-11',
        'end_date' => '2026-04-15',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.start_date.0', 'Annual leave requests for 3 day(s) require at least 3 days notice.');

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Extended vacation.',
        'start_date' => '2026-04-11',
        'end_date' => '2026-04-17',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.start_date.0', 'Annual leave requests for 5 day(s) require at least 7 days notice.');

    $this->postJson('/api/leave/requests', [
        'type' => 'sick',
        'reason' => 'Fever symptoms.',
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-10',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'sick');
});

it('enforces the same notice rules for unpaid leave', function () {
    [$employeeUser] = createLeaveActors(today: '2026-04-10');

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'unpaid',
        'reason' => 'Extended personal leave.',
        'start_date' => '2026-04-11',
        'end_date' => '2026-04-15',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.start_date.0', 'Unpaid leave requests for 5 day(s) require at least 7 days notice.');

    $this->postJson('/api/leave/requests', [
        'type' => 'unpaid',
        'reason' => 'Planned personal leave.',
        'start_date' => '2026-04-12',
        'end_date' => '2026-04-14',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.start_date.0', 'Unpaid leave requests for 3 day(s) require at least 3 days notice.');
});

it('supports half-day leave requests with am pm sessions and 0.5 total days', function () {
    [$employeeUser] = createLeaveActors(
        hireDate: '2026-04-01',
        employeeEmploymentType: 'full_time',
        today: '2026-04-10',
    );

    Passport::actingAs($employeeUser);

    $response = $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Medical appointment.',
        'duration_type' => 'half_day',
        'half_day_session' => 'AM',
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-10',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'annual')
        ->assertJsonPath('data.duration_type', 'half_day')
        ->assertJsonPath('data.half_day_session', 'AM')
        ->assertJsonPath('data.requested_days', 0.5)
        ->assertJsonPath('data.total_days', 0.5);

    $leaveRequest = LeaveRequest::query()->latest('id')->firstOrFail();

    expect($leaveRequest->duration_type)->toBe('half_day')
        ->and($leaveRequest->half_day_session)->toBe('AM');
});

it('requires a valid half-day session and same-day range for half-day requests', function () {
    [$employeeUser] = createLeaveActors(
        hireDate: '2026-04-01',
        employeeEmploymentType: 'full_time',
        today: '2026-04-10',
    );

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'sick',
        'reason' => 'Clinic visit.',
        'duration_type' => 'half_day',
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-10',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.half_day_session.0', 'Please select AM or PM for a half-day leave request.');

    $this->postJson('/api/leave/requests', [
        'type' => 'sick',
        'reason' => 'Clinic visit.',
        'duration_type' => 'half_day',
        'half_day_session' => 'MIDDAY',
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-10',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.half_day_session.0', 'The selected half day session is invalid.');

    $this->postJson('/api/leave/requests', [
        'type' => 'sick',
        'reason' => 'Clinic visit.',
        'duration_type' => 'half_day',
        'half_day_session' => 'PM',
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-11',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.end_date.0', 'Half-day leave must start and end on the same date.');
});

it('requires a non-empty reason when submitting a leave request', function () {
    [$employeeUser] = createLeaveActors();

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Reason is required.');

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => '   ',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Reason is required.');

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'ok',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Please provide a reason for your leave request.');
});

it('returns leave history summary counts with the paginated history response', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveActors();

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Awaiting manager review.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
        'status' => LeaveRequestStatus::Pending,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Approved leave.',
        'start_date' => '2026-04-22',
        'end_date' => '2026-04-22',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDays(2),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'sick',
        'reason' => 'Rejected leave.',
        'start_date' => '2026-04-24',
        'end_date' => '2026-04-24',
        'status' => LeaveRequestStatus::Rejected,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'unpaid',
        'reason' => 'Cancelled leave.',
        'start_date' => '2026-04-25',
        'end_date' => '2026-04-25',
        'status' => LeaveRequestStatus::Cancelled,
    ]);

    Passport::actingAs($employeeUser);

    $this->getJson('/api/leave/me/requests')
        ->assertOk()
        ->assertJsonPath('summary.total_requests', 4)
        ->assertJsonPath('summary.pending_count', 1)
        ->assertJsonPath('summary.approved_count', 1)
        ->assertJsonPath('summary.rejected_count', 1)
        ->assertJsonPath('summary.cancelled_count', 1);
});

it('enforces manager-first then hr-final approval flow', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveActors();
    $hrUser = grantPermissionsToUser($hrUser, ['leave.approve.hr']);

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-21',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($hrUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/hr-review", [
        'status' => LeaveRequestStatus::HrApproved,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'HR can only review leave requests after manager approval.');

    Passport::actingAs($managerUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', LeaveRequestStatus::ManagerApproved)
        ->assertJsonPath('data.status_label', 'Manager Approved')
        ->assertJsonPath('data.approval_stage', 'hr_review')
        ->assertJsonPath('data.manager_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'pending')
        ->assertJsonPath('data.manager_approved_by.id', $managerUser->employee->id);

    Passport::actingAs($hrUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/hr-review", [
        'status' => LeaveRequestStatus::HrApproved,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', LeaveRequestStatus::HrApproved)
        ->assertJsonPath('data.status_label', 'HR Approved')
        ->assertJsonPath('data.approval_stage', 'completed')
        ->assertJsonPath('data.manager_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'approved')
        ->assertJsonPath('data.hr_approved_by.id', $hrUser->employee->id);

    $leaveRequest->refresh();

    expect($leaveRequest->status)->toBe(LeaveRequestStatus::HrApproved)
        ->and($leaveRequest->manager_approved_by)->toBe($managerUser->employee->id)
        ->and($leaveRequest->hr_approved_by)->toBe($hrUser->employee->id);
});

it('uses a dedicated leave approver before falling back to manager_id', function () {
    [$employeeUser, $managerUser] = createLeaveActors();

    $alternateApproverUser = createLeaveUserWithRole('employee', 'dedicated.approver@example.com');
    $alternateApproverEmployee = Employee::query()->create([
        'user_id' => $alternateApproverUser->id,
        'department_id' => $employeeUser->employee->department_id,
        'current_position_id' => $managerUser->employee->current_position_id,
        'first_name' => 'Dedicated',
        'last_name' => 'Approver',
        'email' => 'dedicated.approver.employee@example.com',
        'phone' => '0123456799',
        'hire_date' => '2023-01-15',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $employeeUser->employee->update([
        'leave_approver_id' => $alternateApproverEmployee->id,
    ]);

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Dedicated approver flow.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-21',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($managerUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])->assertForbidden();

    Passport::actingAs($alternateApproverUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', LeaveRequestStatus::ManagerApproved)
        ->assertJsonPath('data.manager_approved_by.id', $alternateApproverEmployee->id);
});

it('fully approves hr-layer leave when the designated final approver acts', function () {
    $executiveDepartment = Department::query()->create([
        'name' => 'Executive Office',
    ]);
    $hrDepartment = Department::query()->create([
        'name' => 'Human Resources',
    ]);
    $executivePosition = Position::query()->create([
        'title' => 'Director',
    ]);
    $headHrPosition = Position::query()->create([
        'title' => 'Head of HR',
    ]);
    $hrOfficerPosition = Position::query()->create([
        'title' => 'HR Officer',
    ]);

    $finalApproverUser = createLeaveUserWithRole('manager', 'hr.final.approver@example.com');
    $finalApproverEmployee = Employee::query()->create([
        'user_id' => $finalApproverUser->id,
        'department_id' => $executiveDepartment->id,
        'current_position_id' => $executivePosition->id,
        'first_name' => 'Final',
        'last_name' => 'Approver',
        'email' => 'hr.final.approver.employee@example.com',
        'phone' => '0123456788',
        'hire_date' => '2022-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $headHrUser = createLeaveUserWithRole('hr', 'head.hr@example.com');
    $headHrEmployee = Employee::query()->create([
        'user_id' => $headHrUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $headHrPosition->id,
        'manager_id' => $finalApproverEmployee->id,
        'leave_approver_id' => $finalApproverEmployee->id,
        'first_name' => 'Head',
        'last_name' => 'Hr',
        'email' => 'head.hr.employee@example.com',
        'phone' => '0123456787',
        'hire_date' => '2022-02-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $hrOfficerUser = createLeaveUserWithRole('hr', 'hr.layer.employee@example.com');
    $hrOfficerEmployee = Employee::query()->create([
        'user_id' => $hrOfficerUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $hrOfficerPosition->id,
        'manager_id' => $headHrEmployee->id,
        'leave_approver_id' => $headHrEmployee->id,
        'first_name' => 'Layer',
        'last_name' => 'Hr',
        'email' => 'hr.layer.employee.record@example.com',
        'phone' => '0123456786',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $hrOfficerEmployee->id,
        'type' => 'annual',
        'reason' => 'HR layer final approval.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-21',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($headHrUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Leave request fully approved successfully.')
        ->assertJsonPath('data.status', LeaveRequestStatus::HrApproved)
        ->assertJsonPath('data.status_label', 'HR Approved')
        ->assertJsonPath('data.approval_stage', 'completed')
        ->assertJsonPath('data.manager_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'approved')
        ->assertJsonPath('data.manager_approved_by.id', $headHrEmployee->id)
        ->assertJsonPath('data.hr_approved_by.id', $headHrEmployee->id);

    $leaveRequest->refresh();

    expect($leaveRequest->status)->toBe(LeaveRequestStatus::HrApproved)
        ->and($leaveRequest->manager_approved_by)->toBe($headHrEmployee->id)
        ->and($leaveRequest->hr_approved_by)->toBe($headHrEmployee->id);
});

it('fully approves head hr leave when the designated executive final approver acts', function () {
    $executiveDepartment = Department::query()->create([
        'name' => 'Executive Office',
    ]);
    $hrDepartment = Department::query()->create([
        'name' => 'Human Resources',
    ]);
    $executivePosition = Position::query()->create([
        'title' => 'Chief Executive Officer',
    ]);
    $headHrPosition = Position::query()->create([
        'title' => 'Head of HR',
    ]);

    $adminUser = createLeaveUserWithRole('admin', 'ceo.final.approver@example.com');
    $adminEmployee = Employee::query()->create([
        'user_id' => $adminUser->id,
        'department_id' => $executiveDepartment->id,
        'current_position_id' => $executivePosition->id,
        'first_name' => 'Chief',
        'last_name' => 'Executive',
        'email' => 'ceo.final.approver.employee@example.com',
        'phone' => '0123456785',
        'hire_date' => '2021-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $headHrUser = createLeaveUserWithRole('hr', 'head.hr.final@example.com');
    $headHrEmployee = Employee::query()->create([
        'user_id' => $headHrUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $headHrPosition->id,
        'manager_id' => $adminEmployee->id,
        'leave_approver_id' => $adminEmployee->id,
        'first_name' => 'Helen',
        'last_name' => 'HeadHr',
        'email' => 'head.hr.final.employee@example.com',
        'phone' => '0123456784',
        'hire_date' => '2022-03-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $headHrEmployee->id,
        'type' => 'sick',
        'reason' => 'Head HR final approver flow.',
        'start_date' => '2026-04-24',
        'end_date' => '2026-04-24',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($adminUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Leave request fully approved successfully.')
        ->assertJsonPath('data.status', LeaveRequestStatus::HrApproved)
        ->assertJsonPath('data.approval_stage', 'completed')
        ->assertJsonPath('data.manager_approved_by.id', $adminEmployee->id)
        ->assertJsonPath('data.hr_approved_by.id', $adminEmployee->id);

    $leaveRequest->refresh();

    expect($leaveRequest->status)->toBe(LeaveRequestStatus::HrApproved)
        ->and($leaveRequest->manager_approved_by)->toBe($adminEmployee->id)
        ->and($leaveRequest->hr_approved_by)->toBe($adminEmployee->id)
        ->and($leaveRequest->hr_approved_at)->not->toBeNull();
});

it('fully approves diana dual leave when the ceo line manager acts as final approver', function () {
    $executiveDepartment = Department::query()->create([
        'name' => 'Executive Office',
    ]);
    $operationsDepartment = Department::query()->create([
        'name' => 'Operations',
    ]);
    $ceoPosition = Position::query()->create([
        'title' => 'Chief Executive Officer',
    ]);
    $managerPosition = Position::query()->create([
        'title' => 'Regional Manager',
    ]);

    $ceoUser = createLeaveUserWithRole('admin', 'alice.ceo@example.com');
    $ceoEmployee = Employee::query()->create([
        'user_id' => $ceoUser->id,
        'department_id' => $executiveDepartment->id,
        'current_position_id' => $ceoPosition->id,
        'first_name' => 'Alice',
        'last_name' => 'CEO',
        'email' => 'alice.ceo.employee@example.com',
        'phone' => '0123456783',
        'hire_date' => '2021-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $dianaUser = createLeaveUserWithRole('manager', 'diana.dual@example.com');
    $dianaEmployee = Employee::query()->create([
        'user_id' => $dianaUser->id,
        'department_id' => $operationsDepartment->id,
        'current_position_id' => $managerPosition->id,
        'manager_id' => $ceoEmployee->id,
        'first_name' => 'Diana',
        'last_name' => 'Dual',
        'email' => 'diana.dual.employee@example.com',
        'phone' => '0123456782',
        'hire_date' => '2022-06-10',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $dianaEmployee->id,
        'type' => 'annual',
        'reason' => 'Diana final approver flow.',
        'start_date' => '2026-04-24',
        'end_date' => '2026-04-25',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($ceoUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Leave request fully approved successfully.')
        ->assertJsonPath('data.status', LeaveRequestStatus::HrApproved)
        ->assertJsonPath('data.status_label', 'HR Approved')
        ->assertJsonPath('data.approval_stage', 'completed')
        ->assertJsonPath('data.manager_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'approved')
        ->assertJsonPath('data.manager_approved_by.id', $ceoEmployee->id)
        ->assertJsonPath('data.hr_approved_by.id', $ceoEmployee->id);

    $leaveRequest->refresh();

    expect($leaveRequest->status)->toBe(LeaveRequestStatus::HrApproved)
        ->and($leaveRequest->manager_approved_by)->toBe($ceoEmployee->id)
        ->and($leaveRequest->hr_approved_by)->toBe($ceoEmployee->id)
        ->and($leaveRequest->hr_approved_at)->not->toBeNull();
});

it('keeps hr approval as a separate step even when the leave approver also has hr approval authority', function () {
    [$employeeUser, $managerUser] = createLeaveActors();
    $managerUser = grantPermissionsToUser($managerUser, ['leave.approve.hr']);

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Dual role approval.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-21',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($managerUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Leave request approved by manager successfully.')
        ->assertJsonPath('data.status', LeaveRequestStatus::ManagerApproved)
        ->assertJsonPath('data.status_label', 'Manager Approved')
        ->assertJsonPath('data.approval_stage', 'hr_review')
        ->assertJsonPath('data.manager_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'pending')
        ->assertJsonPath('data.approval_progress.manager.status', 'approved')
        ->assertJsonPath('data.approval_progress.hr.status', 'pending')
        ->assertJsonPath('data.manager_approved_by.id', $managerUser->employee->id)
        ->assertJsonPath('data.hr_approved_by', null);

    $leaveRequest->refresh();

    expect($leaveRequest->status)->toBe(LeaveRequestStatus::ManagerApproved)
        ->and($leaveRequest->manager_approved_by)->toBe($managerUser->employee->id)
        ->and($leaveRequest->hr_approved_by)->toBeNull();
});

it('rejects hr-stage approval when the user lacks leave.approve.hr permission', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveActors();

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Pending HR permission.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-21',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subMinute(),
        'status' => LeaveRequestStatus::ManagerApproved,
    ]);

    Passport::actingAs($hrUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/hr-review", [
        'status' => LeaveRequestStatus::HrApproved,
    ])->assertForbidden();
});

it('does not let a manager finalize approval without hr approval authority', function () {
    [$employeeUser, $managerUser] = createLeaveActors();

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Manager only approval.',
        'start_date' => '2026-04-22',
        'end_date' => '2026-04-23',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($managerUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', LeaveRequestStatus::ManagerApproved)
        ->assertJsonPath('data.status_label', 'Manager Approved')
        ->assertJsonPath('data.approval_stage', 'hr_review')
        ->assertJsonPath('data.manager_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'pending')
        ->assertJsonPath('data.hr_approved_by', null)
        ->assertJsonPath('data.approval_progress.hr.status', 'pending');
});

it('prevents requester self-approval by manager or hr roles', function () {
    [, $managerUser, $hrUser] = createLeaveActors(prefix: 'base');
    [$managerEmployeeUser] = createLeaveActors(
        prefix: 'manager-self',
        employeeRole: 'manager',
        managerEmail: 'base.manager@example.com',
        managerEmployeeId: $managerUser->employee->id,
    );
    [$hrEmployeeUser] = createLeaveActors(
        prefix: 'hr-self',
        employeeRole: 'hr',
        managerEmail: 'base.manager@example.com',
        managerEmployeeId: $managerUser->employee->id,
    );
    $managerEmployeeUser = grantPermissionsToUser($managerEmployeeUser, ['leave.approve.hr']);
    $hrUser = grantPermissionsToUser($hrUser, ['leave.approve.hr']);
    $hrEmployeeUser = grantPermissionsToUser($hrEmployeeUser, ['leave.approve.hr']);

    $managerLeaveRequest = LeaveRequest::query()->create([
        'employee_id' => $managerEmployeeUser->employee->id,
        'type' => 'annual',
        'start_date' => '2026-04-24',
        'end_date' => '2026-04-24',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($managerEmployeeUser);

    $this->patchJson("/api/leave/requests/{$managerLeaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.leave_request.0', 'You cannot approve or reject your own leave request.');

    $hrLeaveRequest = LeaveRequest::query()->create([
        'employee_id' => $hrEmployeeUser->employee->id,
        'type' => 'sick',
        'start_date' => '2026-04-24',
        'end_date' => '2026-04-24',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDay(),
        'status' => LeaveRequestStatus::ManagerApproved,
    ]);

    Passport::actingAs($hrEmployeeUser);

    $this->patchJson("/api/leave/requests/{$hrLeaveRequest->id}/hr-review", [
        'status' => LeaveRequestStatus::HrApproved,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.leave_request.0', 'You cannot approve or reject your own leave request.');
});

it('rejects leave requests when neither a leave approver nor manager is configured', function () {
    [$employeeUser] = createLeaveActors();

    $employeeUser->employee->update([
        'manager_id' => null,
        'leave_approver_id' => null,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Configuration check.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.employee.0', 'A leave approver or manager must be assigned before a leave request can be submitted.');
});

it('rejects leave requests when the configured leave approver is the requester', function () {
    [$employeeUser] = createLeaveActors();

    $employeeUser->employee->update([
        'leave_approver_id' => $employeeUser->employee->id,
    ]);

    Passport::actingAs($employeeUser);

    $this->postJson('/api/leave/requests', [
        'type' => 'annual',
        'reason' => 'Self approver check.',
        'start_date' => '2026-04-20',
        'end_date' => '2026-04-20',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.employee.0', 'The leave approver configuration is invalid. You cannot approve your own leave request.');
});

it('forbids managers from reviewing leave requests outside their hierarchy', function () {
    [$employeeUser, $managerUser] = createLeaveActors();
    [, $otherManagerUser] = createLeaveActors(prefix: 'other');

    $leaveRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'start_date' => '2026-04-22',
        'end_date' => '2026-04-23',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($otherManagerUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::ManagerApproved,
    ])->assertForbidden();

    Passport::actingAs($managerUser);

    $this->patchJson("/api/leave/requests/{$leaveRequest->id}/manager-review", [
        'status' => LeaveRequestStatus::Rejected,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', LeaveRequestStatus::Rejected);
});

it('allows owners to cancel pending requests and blocks cancelling finalized ones', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveActors();

    $pendingRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'start_date' => '2026-04-24',
        'end_date' => '2026-04-24',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($employeeUser);

    $this->patchJson("/api/leave/requests/{$pendingRequest->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', LeaveRequestStatus::Cancelled)
        ->assertJsonPath('data.status_label', 'Cancelled')
        ->assertJsonPath('data.cancelable', false)
        ->assertJsonPath('data.approval_stage', 'cancelled');

    $approvedRequest = LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'start_date' => '2026-04-28',
        'end_date' => '2026-04-29',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDay(),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subHours(12),
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    $this->patchJson("/api/leave/requests/{$approvedRequest->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Only pending or manager-approved leave requests can be cancelled.');
});

/**
 * @return array{0: User, 1: User, 2: User}
 */
function createLeaveActors(
    string $hireDate = '2024-01-01',
    string $prefix = 'default',
    ?string $employeeEmploymentType = null,
    ?string $employeeRole = null,
    ?string $managerEmail = null,
    ?int $managerEmployeeId = null,
    ?string $today = null,
): array {
    $employeeEmploymentType ??= 'full_time';

    if ($today !== null) {
        test()->travelTo(Carbon::parse($today.' 08:00:00'));
    }

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

    if ($managerEmployeeId === null) {
        $managerUser = createLeaveUserWithRole('manager', $managerEmail ?? "{$prefix}.manager@example.com");
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
    } else {
        $managerUser = User::query()->where('email', $managerEmail ?? "{$prefix}.manager@example.com")->firstOrFail();
        $managerEmployee = $managerUser->employee()->firstOrFail();
    }

    $employeeUser = createLeaveUserWithRole($employeeRole ?? 'employee', "{$prefix}.employee@example.com");
    Employee::query()->create([
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'current_position_id' => $staffPosition->id,
        'manager_id' => $managerEmployee->id,
        'first_name' => ucfirst($prefix).'Employee',
        'last_name' => 'Staff',
        'email' => "{$prefix}.employee.staff@example.com",
        'phone' => '0123456702',
        'hire_date' => $hireDate,
        'employment_type' => $employeeEmploymentType,
        'confirmation_date' => $employeeEmploymentType === 'full_time' ? $hireDate : null,
        'gender' => EmployeeGender::Female->value,
        'status' => 'active',
    ]);

    $hrUser = createLeaveUserWithRole('hr', "{$prefix}.hr@example.com");
    Employee::query()->create([
        'user_id' => $hrUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $hrPosition->id,
        'first_name' => ucfirst($prefix).'Hr',
        'last_name' => 'Officer',
        'email' => "{$prefix}.hr.officer@example.com",
        'phone' => '0123456703',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    return [
        $employeeUser->fresh('employee.department', 'roles'),
        $managerUser->fresh('employee.department', 'roles'),
        $hrUser->fresh('employee.department', 'roles'),
    ];
}

function createLeaveUserWithRole(string $roleName, string $email): User
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

function grantPermissionsToUser(User $user, array $permissionNames): User
{
    $permissionIds = collect($permissionNames)
        ->map(fn (string $permissionName): int => Permission::query()->firstOrCreate(
            ['name' => $permissionName],
            ['description' => $permissionName],
        )->id)
        ->all();

    $user->loadMissing('roles');

    $user->roles->each(function (Role $role) use ($permissionIds): void {
        $role->permissions()->syncWithoutDetaching($permissionIds);
    });

    return $user->fresh('employee.department', 'roles.permissions');
}
