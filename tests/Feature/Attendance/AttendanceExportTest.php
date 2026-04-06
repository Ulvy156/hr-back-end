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

uses(RefreshDatabase::class);

it('allows a self export user to download only their own attendance in excel', function () {
    $employeeUser = createExportUser('employee');
    $otherUser = createExportUser('employee');

    createAttendanceRecord($employeeUser, '2026-04-01', '08:00:00', '17:00:00', 'present');
    createAttendanceRecord($otherUser, '2026-04-01', '09:00:00', '17:00:00', 'late', 60);

    Passport::actingAs($employeeUser);

    $response = $this->get('/api/attendance/export/excel?month=2026-04&employee_id='.$otherUser->employee->id);

    $response->assertOk()->assertDownload('my-attendance-report-2026-04.xlsx');

    $binaryResponse = $response->baseResponse;
    $file = $binaryResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($file);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheetXml)->toContain(trim($employeeUser->employee->first_name.' '.$employeeUser->employee->last_name))
        ->not->toContain(trim($otherUser->employee->first_name.' '.$otherUser->employee->last_name));
});

it('forbids attendance export when the user has no export permission', function () {
    $user = createExportUser('manager');

    Passport::actingAs($user);

    $this->get('/api/attendance/export/pdf?month=2026-04')
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');
});

it('allows an organization wide export user to download a pdf across employees', function () {
    $hrUser = createExportUser('hr');
    $employeeA = createExportUser('employee');
    $employeeB = createExportUser('employee');

    createAttendanceRecord($employeeA, '2026-04-02', '08:05:00', '17:00:00', 'present');
    createAttendanceRecord($employeeB, '2026-04-02', '09:10:00', null, 'checked_in', 70);

    Passport::actingAs($hrUser);

    $response = $this->get('/api/attendance/export/pdf?month=2026-04');

    $response->assertOk()->assertDownload('attendance-report-2026-04.pdf');

    $pdf = file_get_contents($response->baseResponse->getFile()->getPathname());

    expect($pdf)->toContain('%PDF-1.4')
        ->toContain(trim($employeeA->employee->first_name.' '.$employeeA->employee->last_name))
        ->toContain(trim($employeeB->employee->first_name.' '.$employeeB->employee->last_name));
});

it('forbids a manager role from exporting attendance', function () {
    $managerUser = createExportUser('manager');

    Passport::actingAs($managerUser);

    $this->get('/api/attendance/export/pdf?month=2026-04')
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');
});

function createExportUser(string $roleName): User
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
        'first_name' => fake()->unique()->lexify('Emp????'),
        'last_name' => fake()->unique()->lexify('User????'),
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

function createAttendanceRecord(
    User $user,
    string $attendanceDate,
    string $checkInTime,
    ?string $checkOutTime,
    string $status,
    int $lateMinutes = 0,
    int $overtimeMinutes = 0,
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
        'overtime_minutes' => $overtimeMinutes,
        'status' => $status,
        'source' => 'manual',
        'correction_status' => 'none',
    ]);
}
