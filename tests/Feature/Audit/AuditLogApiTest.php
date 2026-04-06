<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('allows admin to list system audit logs with filters', function () {
    $adminUser = createAuditApiUser('admin');
    $employeeUser = createAuditApiUser('employee');
    $auditLogService = app(AuditLogService::class);

    $auditLogService->log(
        logName: 'attendance',
        event: 'check_in',
        description: 'attendance.check_in',
        causer: $employeeUser,
        subject: $employeeUser->employee,
        properties: [
            'ip' => '127.0.0.1',
            'attributes' => ['status' => 'checked_in'],
            'old' => [],
        ],
    );

    $auditLogService->log(
        logName: 'auth',
        event: 'login',
        description: 'auth.login',
        causer: $adminUser,
        subject: $adminUser,
        properties: ['auth_method' => 'password_grant'],
    );

    Passport::actingAs($adminUser);

    $response = $this->getJson('/api/audit-logs?keyword=attendance.check_in&log_name=attendance&causer_id='.$employeeUser->id);

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.logName', 'attendance')
        ->assertJsonPath('data.0.event', 'check_in')
        ->assertJsonPath('data.0.causer.id', $employeeUser->id)
        ->assertJsonPath('data.0.subject.type', Employee::class)
        ->assertJsonPath('data.0.metadata.ip', '127.0.0.1');
});

it('allows admin to export filtered audit logs as excel', function () {
    $adminUser = createAuditApiUser('admin');
    $employeeUser = createAuditApiUser('employee');
    $auditLogService = app(AuditLogService::class);

    $auditLogService->log(
        logName: 'attendance',
        event: 'check_in',
        description: 'attendance.check_in',
        causer: $employeeUser,
        subject: $employeeUser->employee,
        properties: [
            'attributes' => ['status' => 'checked_in'],
            'old' => [],
            'source' => 'self_service',
        ],
    );

    $auditLogService->log(
        logName: 'auth',
        event: 'login',
        description: 'auth.login',
        causer: $adminUser,
        subject: $adminUser,
        properties: ['auth_method' => 'password_grant'],
    );

    Passport::actingAs($adminUser);

    $response = $this->get('/api/audit-logs/export/excel?keyword=attendance.check_in&log_name=attendance');

    $response->assertOk()
        ->assertDownload('audit-logs-'.now()->format('Y-m-d').'.xlsx');

    $zip = new ZipArchive;
    $file = $response->baseResponse->getFile()->getPathname();
    $zip->open($file);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheetXml)->toContain('attendance.check_in')
        ->toContain($employeeUser->employee->full_name)
        ->not->toContain('auth.login');
});

it('allows admin to view a single audit log detail', function () {
    $adminUser = createAuditApiUser('admin');
    $auditLogService = app(AuditLogService::class);

    $activity = $auditLogService->log(
        logName: 'access_control',
        event: 'created',
        description: 'created',
        causer: $adminUser,
        subject: $adminUser,
        properties: [
            'attributes' => ['name' => $adminUser->name],
            'old' => [],
        ],
    );

    Passport::actingAs($adminUser);

    $this->getJson('/api/audit-logs/'.$activity->id)
        ->assertOk()
        ->assertJsonPath('data.id', $activity->id)
        ->assertJsonPath('data.logName', 'access_control')
        ->assertJsonPath('data.causer.id', $adminUser->id)
        ->assertJsonPath('data.subject.type', User::class);
});

it('forbids non admin users from reading system audit logs', function () {
    $hrUser = createAuditApiUser('hr');

    Passport::actingAs($hrUser);

    $this->getJson('/api/audit-logs')
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');
});

it('forbids non admin users from exporting system audit logs', function () {
    $hrUser = createAuditApiUser('hr');

    Passport::actingAs($hrUser);

    $this->get('/api/audit-logs/export/excel')
        ->assertForbidden()
        ->assertJsonPath('message', 'Forbidden.');
});

function createAuditApiUser(string $roleName): User
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
        'first_name' => fake()->unique()->lexify('Aud????'),
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

    return $user->fresh('employee', 'roles');
}
