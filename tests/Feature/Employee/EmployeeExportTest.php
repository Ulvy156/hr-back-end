<?php

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

uses(RefreshDatabase::class);

it('allows hr to export employees as excel using list filters', function () {
    $hrUser = createEmployeeExportActor('hr');
    $department = Department::query()->create(['name' => 'Operations']);
    $otherDepartment = Department::query()->create(['name' => 'Finance']);
    $branch = Branch::factory()->create(['name' => 'HQ', 'code' => 'HQ']);
    $position = Position::query()->create(['title' => 'Coordinator']);
    $manager = createExportEmployee($department, $position, [
        'first_name' => 'Helen',
        'last_name' => 'HR',
        'email' => 'manager@example.com',
    ]);

    createExportEmployee($department, $position, [
        'branch_id' => $branch->id,
        'manager_id' => $manager->id,
        'employee_code' => 'EMP000200',
        'first_name' => 'Alice',
        'last_name' => 'Worker',
        'email' => 'alice.worker@example.com',
        'employment_type' => 'full_time',
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

    createExportEmployee($otherDepartment, $position, [
        'employee_code' => 'EMP000201',
        'first_name' => 'Bob',
        'last_name' => 'Finance',
        'email' => 'bob.finance@example.com',
        'employment_type' => 'contract',
        'hire_date' => '2026-03-01',
        'status' => 'inactive',
    ]);

    Passport::actingAs($hrUser);

    $response = $this->get('/api/employees/export/excel?search=Alice&status=active&department_id='.$department->id.'&branch_id='.$branch->id.'&manager_id='.$manager->id.'&employment_type=full_time&hire_date_from=2026-04-01&hire_date_to=2026-04-30');

    $response
        ->assertOk()
        ->assertDownload();

    /** @var BinaryFileResponse $binaryResponse */
    $binaryResponse = $response->baseResponse;
    $filePath = $binaryResponse->getFile()->getPathname();

    expect(basename($filePath))->toContain('.xlsx');

    $worksheetXml = readEmployeeExportWorksheet($filePath);

    expect($worksheetXml)->toContain('Alice Worker')
        ->and($worksheetXml)->toContain('EMP000200')
        ->and($worksheetXml)->toContain('Operations')
        ->and($worksheetXml)->not->toContain('Bob Finance');

    /** @var Activity|null $activity */
    $activity = Activity::query()
        ->where('log_name', 'employee')
        ->where('event', 'employee.export')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($hrUser->id)
        ->and($activity?->description)->toBe('employee.export')
        ->and($activity?->getExtraProperty('filters')['search'] ?? null)->toBe('Alice')
        ->and($activity?->getExtraProperty('exported_count'))->toBe(1);
});

it('forbids admin from exporting employees', function () {
    $adminUser = createEmployeeExportActor('admin');

    Passport::actingAs($adminUser);

    $this->get('/api/employees/export/excel')
        ->assertForbidden();
});

function createEmployeeExportActor(string $roleName): User
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
        'phone' => '0'.fake()->numerify('#########'),
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

/**
 * @param  array<string, mixed>  $overrides
 */
function createExportEmployee(Department $department, Position $position, array $overrides = []): Employee
{
    $user = User::factory()->create();

    return Employee::query()->create(array_merge([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'current_position_id' => $position->id,
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'email' => fake()->unique()->safeEmail(),
        'phone' => '0'.fake()->numerify('#########'),
        'hire_date' => '2026-01-01',
        'status' => 'active',
    ], $overrides));
}

function readEmployeeExportWorksheet(string $filePath): string
{
    $zip = new ZipArchive;

    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Unable to open exported employee workbook.');
    }

    $worksheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($worksheetXml === false) {
        throw new RuntimeException('Unable to read exported employee worksheet.');
    }

    return $worksheetXml;
}
