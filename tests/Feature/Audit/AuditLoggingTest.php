<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('attendance.work_start_time', '08:00:00');
    config()->set('attendance.work_end_time', '17:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('logs employee creation with the authenticated actor', function () {
    $adminUser = createAuditLoggingUser('admin');
    Passport::actingAs($adminUser);

    $department = Department::query()->create([
        'name' => 'People Operations',
    ]);

    $position = Position::query()->create([
        'title' => 'HR Specialist',
    ]);

    $linkedUser = User::factory()->create([
        'email' => 'audit.employee.user@example.com',
    ]);

    $response = $this->postJson('/api/employees', [
        'user_id' => $linkedUser->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'first_name' => 'Audit',
        'last_name' => 'Employee',
        'email' => 'audit.employee@example.com',
        'phone' => '0123456789',
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    $response->assertCreated();

    /** @var Activity|null $activity */
    $activity = Activity::query()
        ->where('log_name', 'employee')
        ->where('event', 'created')
        ->where('subject_type', Employee::class)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($adminUser->id)
        ->and($activity?->subject_id)->toBe(Employee::query()->latest('id')->value('id'))
        ->and($activity?->description)->toBe('created');
});

it('logs attendance check in as a business audit event', function () {
    $employeeUser = createAuditLoggingUser('employee');

    Passport::actingAs($employeeUser);
    Carbon::setTestNow(Carbon::parse('2026-04-05 08:05:00'));

    $this->postJson('/api/attendance/check-in')
        ->assertCreated()
        ->assertJsonPath('message', 'Check-in recorded successfully.');

    $attendance = Attendance::query()->firstOrFail();

    /** @var Activity|null $activity */
    $activity = Activity::query()
        ->where('log_name', 'attendance')
        ->where('event', 'check_in')
        ->where('subject_type', Attendance::class)
        ->where('subject_id', $attendance->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($employeeUser->id)
        ->and($activity?->description)->toBe('attendance.check_in')
        ->and($activity?->getExtraProperty('employee_id'))->toBe($employeeUser->employee->id)
        ->and($activity?->getExtraProperty('status'))->toBe('checked_in');
});

it('logs attendance exports with actor, scope, and format metadata', function () {
    $hrUser = createAuditLoggingUser('hr');
    $employeeUser = createAuditLoggingUser('employee');

    createAuditLoggingAttendance($employeeUser, '2026-04-02', '08:00:00', '17:00:00', 'present');

    Passport::actingAs($hrUser);

    $this->get('/api/attendance/export/pdf?month=2026-04')
        ->assertOk()
        ->assertDownload('attendance-report-2026-04.pdf');

    /** @var Activity|null $activity */
    $activity = Activity::query()
        ->where('log_name', 'attendance')
        ->where('event', 'exported')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($hrUser->id)
        ->and($activity?->description)->toBe('attendance.exported')
        ->and($activity?->getExtraProperty('format'))->toBe('pdf')
        ->and($activity?->getExtraProperty('scope'))->toBe('all')
        ->and($activity?->getExtraProperty('record_count'))->toBe(1);
});

it('logs access control changes when roles are created', function () {
    $adminUser = createAuditLoggingUser('admin');

    Passport::actingAs($adminUser);

    $role = Role::query()->create([
        'name' => 'auditor',
        'description' => 'Read-only audit access',
    ]);

    /** @var Activity|null $activity */
    $activity = Activity::query()
        ->where('log_name', 'access_control')
        ->where('event', 'created')
        ->where('subject_type', Role::class)
        ->where('subject_id', $role->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($adminUser->id)
        ->and($activity?->description)->toBe('created');
});

function createAuditLoggingUser(string $roleName): User
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

    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)]
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('employee.department', 'roles');
}

function createAuditLoggingAttendance(
    User $user,
    string $attendanceDate,
    string $checkInTime,
    ?string $checkOutTime,
    string $status,
    int $lateMinutes = 0,
): Attendance {
    $checkIn = Carbon::parse($attendanceDate.' '.$checkInTime);
    $checkOut = $checkOutTime !== null ? Carbon::parse($attendanceDate.' '.$checkOutTime) : null;

    return Attendance::query()->create([
        'employee_id' => $user->employee->id,
        'edited_by' => $user->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'attendance_date' => $attendanceDate,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'worked_minutes' => $checkOut !== null ? $checkIn->diffInMinutes($checkOut) : 0,
        'late_minutes' => $lateMinutes,
        'early_leave_minutes' => 0,
        'status' => $status,
        'source' => 'manual',
        'correction_status' => 'none',
    ]);
}
