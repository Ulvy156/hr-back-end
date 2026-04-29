<?php

use App\Models\Branch;
use App\Models\Commune;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Province;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('returns the authenticated user profile with employee details and nested relations', function () {
    config()->set('filesystems.disks.r2.url', 'https://cdn.example.com');
    config()->set('filesystems.disks.r2.key', 'test-key');
    config()->set('filesystems.disks.r2.secret', 'test-secret');
    config()->set('filesystems.disks.r2.bucket', 'test-bucket');
    config()->set('filesystems.disks.r2.endpoint', 'https://example-account-id.r2.cloudflarestorage.com');

    $role = Role::query()->create([
        'name' => 'employee',
        'description' => 'Employee',
    ]);
    $department = Department::query()->create(['name' => 'Operations']);
    $branch = Branch::factory()->create(['name' => 'HQ', 'code' => 'HQ']);
    $currentPosition = Position::query()->create(['title' => 'Support Agent']);
    $previousPosition = Position::query()->create(['title' => 'Support Intern']);
    $managerPosition = Position::query()->create(['title' => 'Manager']);
    $shift = Shift::factory()->create(['name' => 'Morning', 'code' => 'MORNING']);
    $managerUser = User::factory()->create();
    $manager = Employee::query()->create([
        'user_id' => $managerUser->id,
        'department_id' => $department->id,
        'current_position_id' => $managerPosition->id,
        'branch_id' => $branch->id,
        'shift_id' => $shift->id,
        'first_name' => 'Helen',
        'last_name' => 'Manager',
        'email' => 'helen.manager@example.com',
        'phone' => '012345600',
        'hire_date' => '2025-01-01',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'name' => 'Legacy Account Name',
        'email' => 'dara.lim@example.com',
    ]);
    $user->roles()->attach($role);

    $rolePermission = Permission::query()->create([
        'name' => 'auth.profile.role',
        'description' => 'Granted through role',
        'guard_name' => 'api',
    ]);
    $directPermission = Permission::query()->create([
        'name' => 'auth.profile.direct',
        'description' => 'Granted directly',
        'guard_name' => 'api',
    ]);

    $role->givePermissionTo($rolePermission);
    $user->givePermissionTo($directPermission);

    $employee = Employee::query()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'current_position_id' => $currentPosition->id,
        'shift_id' => $shift->id,
        'manager_id' => $manager->id,
        'employee_code' => 'EMP000010',
        'first_name' => 'Dara',
        'last_name' => 'Lim',
        'email' => 'dara.employee@example.com',
        'phone' => '012345678',
        'personal_phone' => '098765432',
        'personal_email' => 'dara.personal@example.com',
        'hire_date' => '2026-04-01',
        'status' => 'active',
    ]);

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

    EmployeeEducation::factory()->create([
        'employee_id' => $employee->id,
        'institution_name' => 'Royal University',
        'education_level' => 'bachelor',
        'degree' => 'BSc',
    ]);

    $employee->emergencyContacts()->create([
        'name' => 'Sibling One',
        'relationship' => 'sibling',
        'phone' => '011111111',
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
        'house_no' => '12',
        'postal_code' => '12000',
        'is_primary' => true,
    ]);
    $employee->employeePositions()->createMany([
        [
            'position_id' => $previousPosition->id,
            'base_salary' => 450,
            'start_date' => '2025-10-01',
            'end_date' => '2026-03-31',
        ],
        [
            'position_id' => $currentPosition->id,
            'base_salary' => 600,
            'start_date' => '2026-04-01',
            'end_date' => null,
        ],
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/auth/profile')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('name', 'Dara Lim')
        ->assertJsonPath('email', 'dara.lim@example.com')
        ->assertJsonPath('roles.0.name', 'employee')
        ->assertJsonPath('employee.id', $employee->id)
        ->assertJsonPath('employee.employee_code', 'EMP000010')
        ->assertJsonPath('employee.department.name', 'Operations')
        ->assertJsonPath('employee.branch.code', 'HQ')
        ->assertJsonPath('employee.current_position.title', 'Support Agent')
        ->assertJsonPath('employee.manager.name', 'Helen Manager')
        ->assertJsonPath('employee.addresses.0.address_type', 'current')
        ->assertJsonPath('employee.addresses.0.province.name_en', 'Phnom Penh')
        ->assertJsonPath('employee.educations.0.institution_name', 'Royal University')
        ->assertJsonPath('employee.emergency_contacts.0.name', 'Sibling One')
        ->assertJsonPath('employee.employee_positions.0.position.title', 'Support Agent');

    expect($response->json('permissions'))
        ->toContain('auth.profile.direct')
        ->toContain('auth.profile.role')
        ->toContain('leave.type.view');

    $meResponse = $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('roles.0.name', 'employee')
        ->assertJsonPath('permission_catalog.naming_pattern', 'module.action[.scope]');

    expect($meResponse->json('permissions'))
        ->toContain('auth.profile.direct')
        ->toContain('auth.profile.role')
        ->toContain('leave.type.view');

    expect($meResponse->json('permission_names'))
        ->toBe($meResponse->json('permissions'))
        ->toContain('auth.profile.direct')
        ->toContain('auth.profile.role')
        ->toContain('leave.type.view')
        ->and(collect($meResponse->json('permission_catalog.modules')))
        ->pluck('key')
        ->toContain('employee')
        ->toContain('attendance')
        ->toContain('leave')
        ->toContain('payroll')
        ->toContain('user_role_management')
        ->toContain('audit_log');
});

it('requires authentication to view the profile endpoint', function () {
    $this->getJson('/api/auth/profile')
        ->assertUnauthorized();
});
