<?php

use App\Models\Attendance;
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
    config()->set('dashboard.work_start_time', '08:00:00');
    config()->set('dashboard.employee_recent_limit', 5);
    config()->set('dashboard.workforce_recent_limit', 10);
    Carbon::setTestNow(Carbon::parse('2026-04-22 09:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns an employee-scoped dashboard summary', function () {
    $user = createDashboardUser();

    Attendance::query()->create([
        'employee_id' => $user->employee->id,
        'edited_by' => $user->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 5),
        'status' => 'present',
    ]);

    Attendance::query()->create([
        'employee_id' => $user->employee->id,
        'edited_by' => $user->id,
        'attendance_date' => dashboardWeekReferenceDate()->toDateString(),
        'check_in' => dashboardWeekReferenceDate()->copy()->setTime(8, 0),
        'check_out' => dashboardWeekReferenceDate()->copy()->setTime(17, 0),
        'status' => 'present',
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('role', 'employee')
        ->assertJsonPath('summary.todayAttendanceStatus', 'checked_in')
        ->assertJsonPath('summary.nextAction', 'scan_out')
        ->assertJsonPath('summary.attendanceThisWeek.totalPresentDays', 2)
        ->assertJsonPath('summary.attendanceThisWeek.lateCount', 1)
        ->assertJsonPath('recentRecords.0.date', now()->toDateString());

    expect($response->json('summary'))->not->toHaveKey('totalEmployees')
        ->and($response->json('extra'))->toBeArray()
        ->and($response->json('extra'))->toBeEmpty();
});

it('returns an hr workforce dashboard without admin-only data', function () {
    $hrUser = createDashboardUser('hr');
    $checkedOutUser = createDashboardUser();
    $checkedInOnlyUser = createDashboardUser();
    $missingUser = createDashboardUser();
    $terminatedUser = createDashboardUser(null, 'terminated');

    Attendance::query()->create([
        'employee_id' => $checkedOutUser->employee->id,
        'edited_by' => $hrUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 0),
        'check_out' => now()->setTime(17, 0),
        'status' => 'present',
    ]);

    Attendance::query()->create([
        'employee_id' => $checkedInOnlyUser->employee->id,
        'edited_by' => $hrUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(9, 15),
        'status' => 'late',
    ]);

    Attendance::query()->create([
        'employee_id' => $terminatedUser->employee->id,
        'edited_by' => $hrUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(7, 55),
        'status' => 'present',
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $missingUser->employee->id,
        'type' => 'annual',
        'start_date' => now()->toDateString(),
        'end_date' => now()->toDateString(),
        'manager_approved_by' => $hrUser->employee->id,
        'manager_approved_at' => now()->subDay(),
        'hr_approved_by' => $hrUser->employee->id,
        'hr_approved_at' => now()->subHours(12),
        'status' => 'hr_approved',
    ]);

    Passport::actingAs($hrUser);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('role', 'hr')
        ->assertJsonPath('summary.totalEmployees', 5)
        ->assertJsonPath('summary.activeEmployees', 4)
        ->assertJsonPath('summary.checkedInTodayCount', 2)
        ->assertJsonPath('summary.checkedOutTodayCount', 1)
        ->assertJsonPath('summary.missingAttendanceCount', 3)
        ->assertJsonPath('summary.lateCountToday', 1)
        ->assertJsonPath('summary.employeesOnLeaveTodayCount', 1)
        ->assertJsonPath('extra.attendanceIssues.missingCheckout', 1)
        ->assertJsonPath('extra.attendanceIssues.lateArrivals', 1)
        ->assertJsonPath('extra.attendanceIssues.incompleteAttendance', 1);

    expect($response->json('summary'))->not->toHaveKey('totalUsers')
        ->and($response->json('recentRecords.0.employee'))->toHaveKey('name')
        ->and($response->json('recentRecords.0.employee'))->toHaveKey('department');
});

it('returns an admin dashboard with user role aggregates', function () {
    $adminUser = createDashboardUser('admin');
    createDashboardUser('hr');
    createDashboardUser('employee');

    Passport::actingAs($adminUser);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('role', 'admin')
        ->assertJsonPath('summary.totalUsers', 3)
        ->assertJsonPath('summary.employeesOnLeaveTodayCount', 0);

    expect(collect($response->json('summary.usersByRole'))->pluck('totalUsers', 'role')->all())
        ->toMatchArray([
            'admin' => 1,
            'employee' => 1,
            'hr' => 1,
        ])
        ->and(collect($response->json('quickActions'))->pluck('key')->all())
        ->toContain('manage_users', 'manage_employees', 'correct_attendance');
});

it('returns a manager dashboard using the self dashboard shape', function () {
    $managerUser = createDashboardUser('manager');

    Attendance::query()->create([
        'employee_id' => $managerUser->employee->id,
        'edited_by' => $managerUser->id,
        'attendance_date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 10),
        'status' => 'present',
    ]);

    Passport::actingAs($managerUser);

    $response = $this->getJson('/api/dashboard');

    $response
        ->assertOk()
        ->assertJsonPath('role', 'manager')
        ->assertJsonPath('summary.todayAttendanceStatus', 'checked_in')
        ->assertJsonPath('summary.nextAction', 'scan_out');

    expect($response->json('summary'))->not->toHaveKey('totalEmployees')
        ->and($response->json('extra'))->toBeArray()
        ->and($response->json('extra'))->toBeEmpty();
});

function createDashboardUser(?string $role = null, string $employeeStatus = 'active'): User
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
        'status' => $employeeStatus,
    ]);

    if ($role !== null) {
        $roleModel = Role::query()->firstOrCreate(
            ['name' => $role],
            ['description' => ucfirst($role)]
        );

        $user->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    return $user->fresh('employee', 'roles');
}

function dashboardWeekReferenceDate(): Carbon
{
    $referenceDate = now()->copy()->subDays(2);

    if ($referenceDate->lt(now()->copy()->startOfWeek())) {
        return now()->copy()->startOfWeek()->addDay();
    }

    return $referenceDate;
}
