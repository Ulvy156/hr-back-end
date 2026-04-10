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

beforeEach(function (): void {
    $this->seed(LeaveTypeSeeder::class);
});

it('returns current leave balances per leave type for the authenticated employee', function () {
    [$employeeUser, $managerUser, $hrUser] = createLeaveBalanceActors(today: '2026-04-10');

    LeaveType::query()->create([
        'code' => 'inactive_extra',
        'name' => 'Inactive Extra Leave',
        'description' => 'Used to verify balances include all leave types from the table.',
        'is_paid' => true,
        'requires_balance' => false,
        'requires_attachment' => false,
        'requires_medical_certificate' => false,
        'auto_exclude_public_holidays' => false,
        'auto_exclude_weekends' => false,
        'gender_restriction' => LeaveTypeGenderRestriction::None->value,
        'min_service_days' => null,
        'max_days_per_request' => null,
        'max_days_per_year' => 3,
        'is_active' => false,
        'sort_order' => 999,
        'metadata' => null,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Approved annual leave.',
        'start_date' => '2026-04-21',
        'end_date' => '2026-04-22',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDays(3),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subDays(2),
        'status' => LeaveRequestStatus::HrApproved,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'annual',
        'reason' => 'Pending annual leave.',
        'start_date' => '2026-04-25',
        'end_date' => '2026-04-25',
        'status' => LeaveRequestStatus::Pending,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'sick',
        'reason' => 'Approved sick leave.',
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-10',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDays(2),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => LeaveRequestStatus::HrApproved,
    ]);
    LeaveRequest::query()->create([
        'employee_id' => $employeeUser->employee->id,
        'type' => 'unpaid',
        'reason' => 'Approved unpaid leave.',
        'start_date' => '2026-04-12',
        'end_date' => '2026-04-13',
        'manager_approved_by' => $managerUser->employee->id,
        'manager_approved_at' => now()->subDays(2),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subDay(),
        'status' => LeaveRequestStatus::HrApproved,
    ]);

    Passport::actingAs($employeeUser);

    $response = $this->getJson('/api/leave/me/balances')
        ->assertOk()
        ->assertJsonCount(6, 'data');

    $annualBalance = collect($response->json('data'))
        ->firstWhere('leave_type.code', LeaveTypeCode::Annual->value);
    $sickBalance = collect($response->json('data'))
        ->firstWhere('leave_type.code', LeaveTypeCode::Sick->value);
    $unpaidBalance = collect($response->json('data'))
        ->firstWhere('leave_type.code', LeaveTypeCode::Unpaid->value);
    $inactiveBalance = collect($response->json('data'))
        ->firstWhere('leave_type.code', 'inactive_extra');

    expect($annualBalance)->toMatchArray([
        'leave_type' => [
            'code' => LeaveTypeCode::Annual->value,
            'name' => 'Annual Leave',
            'label' => 'Annual Leave',
            'is_paid' => true,
            'requires_balance' => true,
        ],
        'year' => 2026,
        'used_days' => 2.0,
    ])
        ->and($annualBalance['total_days'])->toBe(18)
        ->and($annualBalance['remaining_days'])->toBe(16)
        ->and(data_get($sickBalance, 'leave_type.code'))->toBe(LeaveTypeCode::Sick->value)
        ->and($sickBalance['year'])->toBe(2026)
        ->and($sickBalance['total_days'])->toBe(7)
        ->and($sickBalance['used_days'])->toBe(1)
        ->and($sickBalance['remaining_days'])->toBe(6)
        ->and(data_get($unpaidBalance, 'leave_type.code'))->toBe(LeaveTypeCode::Unpaid->value)
        ->and($unpaidBalance['year'])->toBe(2026)
        ->and($unpaidBalance['used_days'])->toBe(2)
        ->and($unpaidBalance['total_days'])->toBeNull()
        ->and($unpaidBalance['remaining_days'])->toBeNull()
        ->and(data_get($inactiveBalance, 'leave_type.code'))->toBe('inactive_extra')
        ->and($inactiveBalance['year'])->toBe(2026)
        ->and($inactiveBalance['total_days'])->toBe(3)
        ->and($inactiveBalance['used_days'])->toBe(0)
        ->and($inactiveBalance['remaining_days'])->toBe(3);
});

it('forbids users without an allowed role from viewing leave balances', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/leave/me/balances')
        ->assertForbidden();
});

/**
 * @return array{0: User, 1: User, 2: User}
 */
function createLeaveBalanceActors(
    string $hireDate = '2026-01-01',
    string $prefix = 'balance',
    ?string $today = null,
): array {
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

    $managerUser = createLeaveBalanceUserWithRole('manager', "{$prefix}.manager@example.com");
    $managerEmployee = Employee::query()->create([
        'user_id' => $managerUser->id,
        'department_id' => $department->id,
        'current_position_id' => $managerPosition->id,
        'first_name' => ucfirst($prefix).'Manager',
        'last_name' => 'Leader',
        'email' => "{$prefix}.manager.employee@example.com",
        'phone' => '0123456801',
        'hire_date' => '2023-01-01',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $employeeUser = createLeaveBalanceUserWithRole('employee', "{$prefix}.employee@example.com");
    Employee::query()->create([
        'user_id' => $employeeUser->id,
        'department_id' => $department->id,
        'current_position_id' => $staffPosition->id,
        'manager_id' => $managerEmployee->id,
        'first_name' => ucfirst($prefix).'Employee',
        'last_name' => 'Staff',
        'email' => "{$prefix}.employee.staff@example.com",
        'phone' => '0123456802',
        'hire_date' => $hireDate,
        'employment_type' => 'full_time',
        'confirmation_date' => $hireDate,
        'gender' => EmployeeGender::Female->value,
        'status' => 'active',
    ]);

    $hrUser = createLeaveBalanceUserWithRole('hr', "{$prefix}.hr@example.com");
    Employee::query()->create([
        'user_id' => $hrUser->id,
        'department_id' => $hrDepartment->id,
        'current_position_id' => $hrPosition->id,
        'first_name' => ucfirst($prefix).'Hr',
        'last_name' => 'Officer',
        'email' => "{$prefix}.hr.officer@example.com",
        'phone' => '0123456803',
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

function createLeaveBalanceUserWithRole(string $roleName, string $email): User
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
