<?php

use App\EmployeeGender;
use App\LeaveTypeCode;
use App\LeaveTypeGenderRestriction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveRequestStatus;
use Carbon\Carbon;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('seeds cambodia leave types idempotently by stable code', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(LeaveTypeSeeder::class);

    expect(LeaveType::query()->count())->toBe(5);

    $annual = LeaveType::query()
        ->where('code', LeaveTypeCode::Annual->value)
        ->firstOrFail();
    $maternity = LeaveType::query()
        ->where('code', LeaveTypeCode::Maternity->value)
        ->firstOrFail();
    $sick = LeaveType::query()
        ->where('code', LeaveTypeCode::Sick->value)
        ->firstOrFail();
    $special = LeaveType::query()
        ->where('code', LeaveTypeCode::Special->value)
        ->firstOrFail();
    $unpaid = LeaveType::query()
        ->where('code', LeaveTypeCode::Unpaid->value)
        ->firstOrFail();

    expect($annual->code)->toBe(LeaveTypeCode::Annual->value)
        ->and($annual->is_paid)->toBeTrue()
        ->and($annual->requires_balance)->toBeTrue()
        ->and($annual->auto_exclude_public_holidays)->toBeTrue()
        ->and($annual->auto_exclude_weekends)->toBeTrue()
        ->and($annual->gender_restriction)->toBe(LeaveTypeGenderRestriction::None)
        ->and($annual->min_service_days)->toBe(365)
        ->and($annual->metadata)->toMatchArray([
            'law_defaults' => [
                'accrual_days_per_month' => 1.5,
                'seniority_bonus_day_every_service_years' => 3,
                'seniority_bonus_days_added' => 1,
                'usable_after_service_days' => 365,
                'exclude_paid_public_holidays_from_deduction' => true,
                'exclude_sick_leave_from_annual_leave_deduction' => true,
            ],
        ])
        ->and($maternity->code)->toBe(LeaveTypeCode::Maternity->value)
        ->and($maternity->is_paid)->toBeTrue()
        ->and($maternity->max_days_per_request)->toBe(90)
        ->and($maternity->metadata)->toMatchArray([
            'law_defaults' => [
                'duration_days' => 90,
                'duration_scope' => 'per_pregnancy',
                'salary_payment_percentage' => 50,
            ],
        ])
        ->and($sick->code)->toBe(LeaveTypeCode::Sick->value)
        ->and($sick->is_paid)->toBeTrue()
        ->and($sick->max_days_per_year)->toBe(7)
        ->and($special->code)->toBe(LeaveTypeCode::Special->value)
        ->and($special->is_paid)->toBeTrue()
        ->and($special->max_days_per_year)->toBe(7)
        ->and($unpaid->code)->toBe(LeaveTypeCode::Unpaid->value)
        ->and($unpaid->is_paid)->toBeFalse()
        ->and($unpaid->requires_balance)->toBeFalse()
        ->and($unpaid->max_days_per_year)->toBeNull()
        ->and($unpaid->description)->toContain('no fixed annual cap')
        ->and($unpaid->metadata)->toMatchArray([
            'policy_notes' => [
                'approval_required' => true,
                'approval_flow' => ['manager', 'hr'],
                'deducts_balance' => false,
                'history_only' => true,
            ],
        ]);
});

it('lists only active leave types ordered by sort order and id', function () {
    $employee = createLeaveTypeActor('employee');

    $firstType = LeaveType::factory()->create([
        'code' => 'bereavement',
        'name' => 'Bereavement Leave',
        'sort_order' => 5,
    ]);
    $inactiveType = LeaveType::factory()->inactive()->create([
        'code' => 'inactive_type',
        'name' => 'Inactive Leave',
        'sort_order' => 1,
    ]);
    $secondType = LeaveType::factory()->create([
        'code' => 'study',
        'name' => 'Study Leave',
        'sort_order' => 5,
    ]);
    $thirdType = LeaveType::factory()->create([
        'code' => 'compassionate',
        'name' => 'Compassionate Leave',
        'sort_order' => 20,
    ]);

    Passport::actingAs($employee);

    $this->getJson('/api/leave/types')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $firstType->id)
        ->assertJsonPath('data.0.code', 'bereavement')
        ->assertJsonPath('data.0.label', 'Bereavement Leave')
        ->assertJsonPath('data.0.supports_half_day', false)
        ->assertJsonPath('data.0.supported_half_day_sessions', [])
        ->assertJsonPath('data.0.notice_rules', [])
        ->assertJsonPath('data.0.is_requestable', true)
        ->assertJsonPath('data.0.request_restriction_reason', null)
        ->assertJsonPath('data.0.balance_snapshot', null)
        ->assertJsonPath('data.1.id', $secondType->id)
        ->assertJsonPath('data.2.id', $thirdType->id)
        ->assertJsonMissing([
            'id' => $inactiveType->id,
            'code' => 'inactive_type',
        ]);
});

it('returns employee-aware leave type metadata for annual leave restrictions and balances', function () {
    $this->seed(LeaveTypeSeeder::class);

    $user = createLeaveTypeActor('employee');
    $department = Department::query()->create(['name' => 'Operations']);
    $position = Position::query()->create(['title' => 'Staff']);

    Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => 'Pheak',
        'last_name' => 'Probation',
        'email' => 'pheak.probation@example.com',
        'phone' => '012300000',
        'hire_date' => '2026-04-01',
        'employment_type' => 'probation',
        'status' => 'active',
    ]);

    Passport::actingAs($user);

    $this->getJson('/api/leave/types')
        ->assertOk()
        ->assertJsonPath('data.0.code', LeaveTypeCode::Annual->value)
        ->assertJsonPath('data.0.notice_rules.0.leave_days_gt', 4)
        ->assertJsonPath('data.0.notice_rules.0.minimum_notice_days', 7)
        ->assertJsonPath('data.0.notice_rules.1.leave_days_gt', 2)
        ->assertJsonPath('data.0.notice_rules.1.minimum_notice_days', 3)
        ->assertJsonPath('data.0.supports_half_day', true)
        ->assertJsonPath('data.0.supported_half_day_sessions.0', 'AM')
        ->assertJsonPath('data.0.supported_half_day_sessions.1', 'PM')
        ->assertJsonPath('data.0.notice_rule_text', 'Annual leave requests for more than 2 days require at least 3 days notice, and requests for more than 4 days require at least 7 days notice.')
        ->assertJsonPath('data.0.is_requestable', false)
        ->assertJsonPath('data.0.request_restriction_reason', 'Employees on probation cannot request annual leave. Please use unpaid leave instead.')
        ->assertJsonPath('data.0.balance_snapshot.year', 2026)
        ->assertJsonPath('data.1.code', LeaveTypeCode::Sick->value)
        ->assertJsonPath('data.1.supports_half_day', true)
        ->assertJsonPath('data.1.supported_half_day_sessions.0', 'AM')
        ->assertJsonPath('data.1.supported_half_day_sessions.1', 'PM')
        ->assertJsonPath('data.1.is_requestable', false)
        ->assertJsonPath('data.1.request_restriction_reason', 'Only full-time employees can request this leave type. Please use unpaid leave instead.')
        ->assertJsonPath('data.4.code', LeaveTypeCode::Unpaid->value)
        ->assertJsonPath('data.4.is_paid', false)
        ->assertJsonPath('data.4.requires_balance', false)
        ->assertJsonPath('data.4.max_days_per_year', null)
        ->assertJsonPath('data.4.notice_rules.0.leave_days_gt', 4)
        ->assertJsonPath('data.4.notice_rules.0.minimum_notice_days', 7)
        ->assertJsonPath('data.4.notice_rules.1.leave_days_gt', 2)
        ->assertJsonPath('data.4.notice_rules.1.minimum_notice_days', 3)
        ->assertJsonPath('data.4.notice_rule_text', 'Unpaid leave requests for more than 2 days require at least 3 days notice, and requests for more than 4 days require at least 7 days notice.')
        ->assertJsonPath('data.4.is_requestable', true)
        ->assertJsonPath('data.4.request_restriction_reason', null)
        ->assertJsonPath('data.4.description', 'Unpaid leave has no fixed annual cap by default under current policy. It is granted only after employee request and written employer approval through the manager-to-HR workflow, does not deduct from leave balance, and is tracked for history and audit purposes.');
});

it('returns full-time annual balance snapshots using full remaining balance without pending deductions', function () {
    $this->seed(LeaveTypeSeeder::class);
    test()->travelTo(Carbon::parse('2026-04-10 08:00:00'));

    $department = Department::query()->create(['name' => 'Operations Full Time']);
    $hrDepartment = Department::query()->create(['name' => 'HR Full Time']);
    $managerPosition = Position::query()->create(['title' => 'Manager Full Time']);
    $staffPosition = Position::query()->create(['title' => 'Staff Full Time']);
    $hrPosition = Position::query()->create(['title' => 'HR Officer Full Time']);

    $managerUser = createLeaveTypeActor('manager', 'leave-type.manager@example.com');
    $managerEmployee = Employee::query()->create([
        'user_id' => $managerUser->id,
        'department_id' => $department->id,
        'current_position_id' => $managerPosition->id,
        'first_name' => 'Manager',
        'last_name' => 'Leader',
        'email' => 'leave-type.manager.employee@example.com',
        'phone' => '012300001',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $employeeUser = createLeaveTypeActor('employee', 'leave-type.employee@example.com');
    $employee = Employee::query()->create([
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'current_position_id' => $staffPosition->id,
        'manager_id' => $managerEmployee->id,
        'first_name' => 'Pheak',
        'last_name' => 'Fulltime',
        'email' => 'leave-type.employee.staff@example.com',
        'phone' => '012300002',
        'hire_date' => '2026-04-01',
        'employment_type' => 'full_time',
        'confirmation_date' => '2026-04-01',
        'gender' => EmployeeGender::Female->value,
        'status' => 'active',
    ]);

    $hrUser = createLeaveTypeActor('hr', 'leave-type.hr@example.com');
    $hrEmployee = Employee::query()->create([
        'user_id' => $hrUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $hrPosition->id,
        'first_name' => 'Hr',
        'last_name' => 'Officer',
        'email' => 'leave-type.hr.officer@example.com',
        'phone' => '012300003',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'annual',
        'reason' => 'Approved annual leave.',
        'start_date' => '2026-04-14',
        'end_date' => '2026-04-15',
        'manager_approved_by' => $managerEmployee->id,
        'manager_approved_at' => now()->subDays(2),
        'hr_approved_by' => $hrEmployee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'type' => 'annual',
        'reason' => 'Pending annual leave.',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-15',
        'status' => LeaveRequestStatus::Pending,
    ]);

    Passport::actingAs($employeeUser);

    $annualLeave = collect($this->getJson('/api/leave/types')->assertOk()->json('data'))
        ->firstWhere('code', LeaveTypeCode::Annual->value);

    expect($annualLeave)->not->toBeNull()
        ->and(data_get($annualLeave, 'balance_snapshot.year'))->toBe(2026)
        ->and(data_get($annualLeave, 'balance_snapshot.entitlement_days'))->toBe(18)
        ->and(data_get($annualLeave, 'balance_snapshot.used_days'))->toBe(2)
        ->and(data_get($annualLeave, 'balance_snapshot.reserved_days'))->toBe(10)
        ->and(data_get($annualLeave, 'balance_snapshot.available_days'))->toBe(16);
});

it('forbids users without an allowed role from listing leave types', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/leave/types')
        ->assertForbidden();
});

function createLeaveTypeActor(string $roleName, ?string $email = null): User
{
    $user = User::factory()->create(
        $email === null ? [] : ['email' => $email]
    );
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
}
