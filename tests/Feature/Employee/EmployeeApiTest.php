<?php

use App\EmployeeStatus;
use App\Models\Branch;
use App\Models\Commune;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\Position;
use App\Models\Province;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('filesystems.disks.r2.url', 'https://cdn.example.com');
    config()->set('filesystems.disks.r2.key', 'test-key');
    config()->set('filesystems.disks.r2.secret', 'test-secret');
    config()->set('filesystems.disks.r2.bucket', 'test-bucket');
    config()->set('filesystems.disks.r2.endpoint', 'https://example-account-id.r2.cloudflarestorage.com');
});

it('lists employees with filters sorting and pagination for admin users', function () {
    $admin = createEmployeeActor('admin');
    $departmentA = Department::query()->create(['name' => 'Finance']);
    $departmentB = Department::query()->create(['name' => 'Operations']);
    $branch = Branch::factory()->create();
    $position = Position::query()->create(['title' => 'Officer']);
    $manager = createEmployeeRecord($departmentA, $position, ['first_name' => 'M', 'last_name' => 'Manager']);

    createEmployeeRecord($departmentA, $position, [
        'branch_id' => $branch->id,
        'manager_id' => $manager->id,
        'first_name' => 'Alice',
        'last_name' => 'Admin',
        'employee_code' => 'EMP000200',
        'email' => 'alice.employee@example.com',
        'status' => 'active',
        'employment_type' => 'full_time',
        'hire_date' => '2026-04-01',
    ]);

    createEmployeeRecord($departmentB, $position, [
        'first_name' => 'Bob',
        'last_name' => 'Staff',
        'employee_code' => 'EMP000201',
        'email' => 'bob.employee@example.com',
        'status' => 'inactive',
        'employment_type' => 'contract',
        'hire_date' => '2026-03-01',
    ]);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/employees?search=Alice&status=active&department_id='.$departmentA->id.'&branch_id='.$branch->id.'&manager_id='.$manager->id.'&employment_type=full_time&hire_date_from=2026-04-01&hire_date_to=2026-04-30&sort_by=first_name&sort_direction=asc&per_page=20');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.first_name', 'Alice')
        ->assertJsonPath('data.0.department.name', 'Finance')
        ->assertJsonPath('data.0.branch.id', $branch->id)
        ->assertJsonPath('data.0.manager.id', $manager->id)
        ->assertJsonPath('data.0.current_position.title', 'Officer')
        ->assertJsonPath('meta.total', 1);
});

it('allows an employee to view only their own employee profile', function () {
    $employeeUser = createEmployeeActor('employee');
    $employee = $employeeUser->employee;
    $province = Province::query()->create([
        'source_id' => 1,
        'code' => '01',
        'name_kh' => 'ភ្នំពេញ',
        'name_en' => 'Phnom Penh',
    ]);
    $district = District::query()->create([
        'source_id' => 101,
        'code' => '101',
        'province_id' => $province->id,
        'name_kh' => 'ចំការមន',
        'name_en' => 'Chamkar Mon',
        'type' => 'ក្រុង',
    ]);
    $commune = Commune::query()->create([
        'source_id' => 10101,
        'code' => '10101',
        'district_id' => $district->id,
        'name_kh' => 'ទន្លេបាសាក់',
        'name_en' => 'Tonle Basak',
    ]);
    $village = Village::query()->create([
        'source_id' => 1010101,
        'code' => '1010101',
        'commune_id' => $commune->id,
        'name_kh' => 'ភូមិ១',
        'name_en' => 'Village 1',
        'is_not_active' => false,
    ]);

    expect($employee)->not->toBeNull();

    EmployeeEducation::query()->create([
        'employee_id' => $employee->id,
        'institution_name' => 'Royal University',
        'education_level' => 'bachelor',
        'degree' => 'BSc',
    ]);

    $employee->emergencyContacts()->create([
        'name' => 'Sibling One',
        'relationship' => 'sibling',
        'phone' => '012345678',
        'email' => 'sibling@example.com',
        'is_primary' => true,
    ]);
    $employee->addresses()->create([
        'address_type' => 'current',
        'province_id' => $province->id,
        'district_id' => $district->id,
        'commune_id' => $commune->id,
        'village_id' => $village->id,
        'address_line' => 'House 12',
        'street' => 'Street 271',
        'is_primary' => true,
    ]);

    Passport::actingAs($employeeUser);

    $response = $this->getJson("/api/employees/{$employee->id}?include=educations,emergency_contacts");

    $response
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('user_id', $employeeUser->id)
        ->assertJsonPath('addresses.0.address_type', 'current')
        ->assertJsonPath('addresses.0.province.name_en', 'Phnom Penh')
        ->assertJsonPath('educations.0.institution_name', 'Royal University')
        ->assertJsonPath('emergency_contacts.0.name', 'Sibling One');
});

it('forbids an employee from viewing another employee profile', function () {
    $employeeUser = createEmployeeActor('employee');
    $otherEmployee = createEmployeeRecord(
        Department::query()->create(['name' => 'People']),
        Position::query()->create(['title' => 'Executive'])
    );

    Passport::actingAs($employeeUser);

    $this->getJson("/api/employees/{$otherEmployee->id}")
        ->assertForbidden();
});

it('creates employees with nested emergency contacts educations and employee positions', function () {
    $hr = createEmployeeActor('hr');
    $department = Department::query()->create(['name' => 'Support']);
    $position = Position::query()->create(['title' => 'Support Agent']);
    $previousPosition = Position::query()->create(['title' => 'Support Intern']);
    $branch = Branch::factory()->create(['name' => 'HQ']);
    $shift = Shift::factory()->create(['name' => 'Day Shift']);
    $user = User::factory()->create();

    Passport::actingAs($hr);

    $response = $this->postJson('/api/employees', [
        'user_id' => $user->id,
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'current_position_id' => $position->id,
        'shift_id' => $shift->id,
        'first_name' => 'Dara',
        'last_name' => 'Lim',
        'email' => 'dara.lim@example.com',
        'phone' => '010222333',
        'hire_date' => '2026-04-01',
        'status' => 'active',
        'include' => ['emergency_contacts', 'educations', 'employee_positions'],
        'emergency_contacts' => [
            [
                'name' => 'Primary Contact',
                'relationship' => 'spouse',
                'phone' => '011111111',
                'email' => 'primary@example.com',
                'is_primary' => true,
            ],
            [
                'name' => 'Second Contact',
                'relationship' => 'parent',
                'phone' => '022222222',
            ],
        ],
        'educations' => [
            [
                'institution_name' => 'Royal University of Phnom Penh',
                'education_level' => 'bachelor',
                'degree' => 'Bachelor of Management',
                'field_of_study' => 'Management',
                'graduation_year' => 2022,
            ],
        ],
        'employee_positions' => [
            [
                'position_id' => $previousPosition->id,
                'base_salary' => 450,
                'start_date' => '2025-10-01',
                'end_date' => '2026-03-31',
            ],
            [
                'position_id' => $position->id,
                'base_salary' => 600,
                'start_date' => '2026-04-01',
                'end_date' => null,
            ],
        ],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('first_name', 'Dara')
        ->assertJsonPath('employee_code', 'EMP000002')
        ->assertJsonPath('emergency_contacts.0.name', 'Primary Contact')
        ->assertJsonPath('emergency_contacts.0.is_primary', true)
        ->assertJsonPath('educations.0.institution_name', 'Royal University of Phnom Penh')
        ->assertJsonPath('employee_positions.0.position.title', 'Support Agent')
        ->assertJsonPath('current_position.id', $position->id);

    $employee = Employee::query()->where('email', 'dara.lim@example.com')->firstOrFail();

    expect($employee->emergencyContacts()->count())->toBe(2)
        ->and($employee->educations()->count())->toBe(1)
        ->and($employee->employeePositions()->count())->toBe(2)
        ->and($employee->current_position_id)->toBe($position->id)
        ->and($user->fresh()?->getRawOriginal('name'))->toBe('Dara Lim');
});

it('updates soft deletes and restores employees for admin users', function () {
    $admin = createEmployeeActor('admin');
    $department = Department::query()->create(['name' => 'IT']);
    $position = Position::query()->create(['title' => 'Developer']);
    $nextPosition = Position::query()->create(['title' => 'Senior Developer']);
    $employee = createEmployeeRecord($department, $position, [
        'first_name' => 'Old',
        'last_name' => 'Name',
        'email' => 'old.name@example.com',
    ]);

    Passport::actingAs($admin);

    $this->patchJson("/api/employees/{$employee->id}", [
        'first_name' => 'New',
        'employment_type' => 'contract',
        'include' => ['educations', 'employee_positions'],
        'educations' => [
            [
                'institution_name' => 'National University',
                'education_level' => 'master',
                'degree' => 'Master of Science',
            ],
        ],
        'employee_positions' => [
            [
                'position_id' => $position->id,
                'base_salary' => 800,
                'start_date' => '2025-01-01',
                'end_date' => '2026-03-31',
            ],
            [
                'position_id' => $nextPosition->id,
                'base_salary' => 1000,
                'start_date' => '2026-04-01',
                'end_date' => null,
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('first_name', 'New')
        ->assertJsonPath('employment_type', 'contract')
        ->assertJsonPath('educations.0.degree', 'Master of Science')
        ->assertJsonPath('employee_positions.0.position.title', 'Senior Developer')
        ->assertJsonPath('current_position.id', $nextPosition->id);

    $this->deleteJson("/api/employees/{$employee->id}")
        ->assertNoContent();

    expect(Employee::query()->find($employee->id))->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id)?->deleted_at)->not->toBeNull()
        ->and($employee->user()->first()?->getRawOriginal('name'))->toBe('New Name');

    $this->postJson("/api/employees/{$employee->id}/restore")
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('first_name', 'New');

    expect(Employee::query()->find($employee->id))->not->toBeNull();
});

it('activates and deactivates employees with dedicated endpoints', function () {
    $hr = createEmployeeActor('hr');
    $department = Department::query()->create(['name' => 'Compliance']);
    $position = Position::query()->create(['title' => 'Officer']);
    $employee = createEmployeeRecord($department, $position, [
        'first_name' => 'Status',
        'last_name' => 'User',
        'email' => 'status.user@example.com',
        'status' => 'active',
    ]);

    Passport::actingAs($hr);

    $this->postJson("/api/employees/{$employee->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('status', 'inactive');

    expect($employee->fresh()?->status)->toBe(EmployeeStatus::Inactive);

    $this->postJson("/api/employees/{$employee->id}/activate")
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('status', 'active');

    expect($employee->fresh()?->status)->toBe(EmployeeStatus::Active);
});

it('terminates and unterminates employees with dedicated endpoints', function () {
    $hr = createEmployeeActor('hr');
    $department = Department::query()->create(['name' => 'Operations']);
    $position = Position::query()->create(['title' => 'Analyst']);
    $employee = createEmployeeRecord($department, $position, [
        'first_name' => 'Terminate',
        'last_name' => 'User',
        'email' => 'terminate.user@example.com',
        'status' => 'active',
    ]);

    Passport::actingAs($hr);

    $response = $this->postJson("/api/employees/{$employee->id}/terminate", [
        'termination_date' => '2026-05-31',
        'last_working_date' => '2026-05-31',
    ])->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('status', 'terminated');

    $freshEmployee = $employee->fresh();

    expect($response->json('termination_date'))->toBe($freshEmployee?->termination_date?->toJSON())
        ->and($response->json('last_working_date'))->toBe($freshEmployee?->last_working_date?->toJSON())
        ->and($freshEmployee?->status)->toBe(EmployeeStatus::Terminated)
        ->and($freshEmployee?->termination_date?->toDateString())->toBe('2026-05-31')
        ->and($freshEmployee?->last_working_date?->toDateString())->toBe('2026-05-31');

    $this->postJson("/api/employees/{$employee->id}/unterminate")
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('termination_date', null)
        ->assertJsonPath('last_working_date', null);

    expect($employee->fresh()?->status)->toBe(EmployeeStatus::Active)
        ->and($employee->fresh()?->termination_date)->toBeNull()
        ->and($employee->fresh()?->last_working_date)->toBeNull();
});

function createEmployeeActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $department = Department::query()->create([
        'name' => fake()->unique()->company(),
    ]);

    $position = Position::query()->create([
        'title' => fake()->unique()->jobTitle(),
    ]);

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

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('employee', 'roles');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createEmployeeRecord(Department $department, Position $position, array $overrides = []): Employee
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
