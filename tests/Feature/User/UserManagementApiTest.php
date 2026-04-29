<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('lists admin users with filters and shaped responses', function () {
    $admin = createUserManagementActor('admin');
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    $hrRole = Role::query()->firstOrCreate(
        ['name' => 'hr'],
        ['description' => 'HR'],
    );

    $visibleUser = createManagedUser(
        [$employeeRole],
        ['name' => 'Visible User', 'email' => 'visible@example.com'],
        [
            'employee_code' => 'EMP000201',
            'first_name' => 'Visible',
            'last_name' => 'Sok',
            'status' => 'active',
        ],
    );

    createManagedUser(
        [$hrRole],
        ['name' => 'Hidden User', 'email' => 'hidden@example.com'],
        [
            'employee_code' => 'EMP000202',
            'first_name' => 'Hidden',
            'last_name' => 'Chan',
            'status' => 'inactive',
        ],
    );

    Passport::actingAs($admin);

    $this->getJson('/api/users?search=Visible&role_id='.$employeeRole->id.'&employee_status=active&employee_id='.$visibleUser->employee?->id.'&per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.id', $visibleUser->id)
        ->assertJsonPath('data.0.name', 'Visible Sok')
        ->assertJsonPath('data.0.employee.employee_code', 'EMP000201')
        ->assertJsonPath('data.0.employee.full_name', 'Visible Sok')
        ->assertJsonPath('data.0.roles.0.name', 'employee')
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/users/roles')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'admin')
        ->assertJsonPath('data.0.permissions.0', 'attendance.audit.view')
        ->assertJsonFragment(['name' => 'employee'])
        ->assertJsonFragment(['name' => 'hr']);
});

it('lists permissions for assignment ui using permission-based access', function () {
    collect(PermissionName::cases())->each(function (PermissionName $permission): void {
        Permission::query()->firstOrCreate(
            ['name' => $permission->value, 'guard_name' => 'api'],
            ['description' => $permission->description()],
        );
    });

    $actor = User::factory()->create();
    $actor->givePermissionTo(PermissionName::PermissionView->value);

    $customPermission = Permission::query()->create([
        'name' => 'zeta.permission',
        'description' => 'Custom permission for sorting checks',
        'guard_name' => 'api',
    ]);

    Passport::actingAs($actor);

    $response = $this->getJson('/api/permissions')
        ->assertOk();

    expect($response->json())->toBeArray()
        ->and($response->json('0.id'))->toBeInt()
        ->and($response->json('0.name'))->toBe('attendance.audit.view')
        ->and($response->json('0.description'))->toBeString()
        ->and($response->json('0.module'))->toBe('attendance')
        ->and($response->json('0.module_label'))->toBe('Attendance')
        ->and($response->json('0.system_defined'))->toBeTrue()
        ->and($response->json('0.recommended_roles'))->toBeArray()
        ->and(collect($response->json())->pluck('name')->all())->toBe(
            collect($response->json())->pluck('name')->sort()->values()->all()
        )
        ->and(collect($response->json())->pluck('name')->unique()->values()->all())->toBe(
            collect($response->json())->pluck('name')->values()->all()
        )
        ->and(collect($response->json())->pluck('name')->all())->toContain($customPermission->name)
        ->and(collect($response->json())->firstWhere('name', $customPermission->name)['description'] ?? null)->toBe($customPermission->description)
        ->and(collect($response->json())->firstWhere('name', $customPermission->name)['module'] ?? null)->toBe('custom')
        ->and(collect($response->json())->firstWhere('name', $customPermission->name)['recommended_roles'] ?? null)->toBe([])
        ->and(collect($response->json())->firstWhere('name', $customPermission->name)['system_defined'] ?? null)->toBeFalse()
        ->and(collect($response->json())->pluck('name')->all())->toContain(PermissionName::PermissionView->value)
        ->and(collect($response->json())->pluck('name')->all())->not->toContain('dashboard.view.admin')
        ->and(collect($response->json())->pluck('name')->all())->not->toContain('dashboard.view.hr')
        ->and(collect($response->json())->pluck('name')->all())->not->toContain('dashboard.view.self');
});

it('returns a user access summary with role, direct, and effective permissions', function () {
    $admin = createUserManagementActor('admin');
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    $managedUser = createManagedUser(
        [$employeeRole],
        ['name' => 'Access User', 'email' => 'access.user@example.com'],
        ['employee_code' => 'EMP000250', 'first_name' => 'Access', 'last_name' => 'Person'],
    );

    $managedUser->givePermissionTo(PermissionName::LeaveApproveHr->value);

    Passport::actingAs($admin);

    $response = $this->getJson("/api/users/{$managedUser->id}/access")
        ->assertOk()
        ->assertJsonPath('id', $managedUser->id)
        ->assertJsonPath('name', 'Access Person')
        ->assertJsonPath('roles.0.name', 'employee')
        ->assertJsonPath('direct_permissions.0', PermissionName::LeaveApproveHr->value);

    expect($response->json('role_permissions'))
        ->toContain(PermissionName::LeaveRequestCreate->value)
        ->toContain(PermissionName::LeaveRequestViewSelf->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value)
        ->and($response->json('effective_permissions'))
        ->toContain(PermissionName::LeaveApproveHr->value)
        ->toContain(PermissionName::LeaveRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value);
});

it('syncs user roles and direct permissions through the admin access endpoints', function () {
    $admin = createUserManagementActor('admin');
    $targetUser = User::factory()->create([
        'name' => 'Target User',
        'email' => 'target.user@example.com',
    ]);
    Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    Role::query()->firstOrCreate(
        ['name' => 'hr'],
        ['description' => 'HR'],
    );

    Passport::actingAs($admin);

    $this->patchJson("/api/users/{$targetUser->id}/roles", [
        'roles' => ['employee'],
    ])
        ->assertOk()
        ->assertJsonPath('roles.0.name', 'employee')
        ->assertJsonPath('direct_permissions', []);

    $permissionResponse = $this->patchJson("/api/users/{$targetUser->id}/permissions", [
        'permissions' => [PermissionName::LeaveApproveHr->value],
    ]);

    $permissionResponse
        ->assertOk()
        ->assertJsonPath('direct_permissions.0', PermissionName::LeaveApproveHr->value);

    expect($permissionResponse->json('effective_permissions'))->toContain(PermissionName::LeaveApproveHr->value);

    $accessResponse = $this->patchJson("/api/users/{$targetUser->id}/access", [
        'roles' => ['hr'],
        'permissions' => [PermissionName::AuditLogView->value],
    ])
        ->assertOk()
        ->assertJsonPath('roles.0.name', 'hr')
        ->assertJsonPath('direct_permissions.0', PermissionName::AuditLogView->value);

    expect($accessResponse->json('effective_permissions'))
        ->toContain(PermissionName::AuditLogView->value)
        ->toContain(PermissionName::EmployeeManage->value)
        ->toContain(PermissionName::LeaveApproveHr->value);

    $targetUser->refresh();

    expect($targetUser->getRoleNames()->all())->toBe(['hr'])
        ->and($targetUser->getDirectPermissions()->pluck('name')->all())->toBe([PermissionName::AuditLogView->value]);
});

it('validates managed role names when syncing user access', function () {
    $admin = createUserManagementActor('admin');
    $targetUser = User::factory()->create();

    Passport::actingAs($admin);

    $this->patchJson("/api/users/{$targetUser->id}/roles", [
        'roles' => ['auditor'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles.0']);
});

it('rejects assigning more than one role through access management endpoints', function () {
    $admin = createUserManagementActor('admin');
    $targetUser = User::factory()->create();
    Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    Role::query()->firstOrCreate(
        ['name' => 'hr'],
        ['description' => 'HR'],
    );

    Passport::actingAs($admin);

    $this->patchJson("/api/users/{$targetUser->id}/roles", [
        'roles' => ['employee', 'hr'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);

    $this->patchJson("/api/users/{$targetUser->id}/access", [
        'roles' => ['employee', 'hr'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles']);
});

it('prevents removing the last access administrator', function () {
    $admin = createUserManagementActor('admin');

    Passport::actingAs($admin);

    $this->patchJson("/api/users/{$admin->id}/access", [
        'roles' => [],
        'permissions' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['access']);
});

it('allows admin to create and update users with role assignment', function () {
    $admin = createUserManagementActor('admin');
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    $hrRole = Role::query()->firstOrCreate(
        ['name' => 'hr'],
        ['description' => 'HR'],
    );
    $firstEmployee = createStandaloneEmployee([
        'employee_code' => 'EMP000301',
        'first_name' => 'Create',
        'last_name' => 'Target',
        'status' => 'active',
    ]);
    $secondEmployee = createStandaloneEmployee([
        'employee_code' => 'EMP000302',
        'first_name' => 'Update',
        'last_name' => 'Target',
        'status' => 'active',
    ]);

    Passport::actingAs($admin);

    $createResponse = $this->postJson('/api/users', [
        'name' => 'Account User',
        'email' => 'account.user@example.com',
        'password' => 'Password123!',
        'employee_id' => $firstEmployee->id,
        'role_ids' => [$employeeRole->id],
    ]);

    $userId = $createResponse->json('id');

    $createResponse
        ->assertCreated()
        ->assertJsonPath('name', 'Create Target')
        ->assertJsonPath('employee.id', $firstEmployee->id)
        ->assertJsonPath('roles.0.name', 'employee');

    $this->putJson("/api/users/{$userId}", [
        'name' => 'Account User Updated',
        'email' => 'account.user.updated@example.com',
        'employee_id' => $secondEmployee->id,
        'role_ids' => [$hrRole->id],
    ])
        ->assertOk()
        ->assertJsonPath('name', 'Update Target')
        ->assertJsonPath('employee.id', $secondEmployee->id)
        ->assertJsonCount(1, 'roles')
        ->assertJsonFragment(['name' => 'hr']);

    $user = User::query()->with(['roles', 'employee'])->findOrFail($userId);

    expect($user->employee?->id)->toBe($secondEmployee->id)
        ->and($user->getRawOriginal('name'))->toBe('Update Target')
        ->and($user->roles->pluck('name')->values()->all())->toBe(['hr'])
        ->and($firstEmployee->fresh()?->user_id)->toBeNull()
        ->and($secondEmployee->fresh()?->user_id)->toBe($userId);
});

it('defaults new users to the employee role when role ids are omitted', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = createUserManagementActor('admin');
    $employee = createStandaloneEmployee([
        'employee_code' => 'EMP000350',
        'first_name' => 'Defaulted',
        'last_name' => 'Employee',
        'email' => 'defaulted.employee.profile@example.com',
    ]);

    Passport::actingAs($admin);

    $response = $this->postJson('/api/users', [
        'name' => 'Defaulted User',
        'email' => 'defaulted.employee@example.com',
        'password' => 'Password123!',
        'employee_id' => $employee->id,
    ])
        ->assertCreated()
        ->assertJsonPath('roles.0.name', 'employee');

    $user = User::query()
        ->with(['roles.permissions', 'permissions', 'employee'])
        ->findOrFail($response->json('id'));

    expect($user->roles->pluck('name')->all())->toBe(['employee'])
        ->and($user->getDirectPermissions()->pluck('name')->all())->toBe([])
        ->and($user->getAllPermissions()->pluck('name')->all())
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestViewSelf->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value)
        ->and($user->employee?->id)->toBe($employee->id);
});

it('creates users with inherited default role permissions and keeps direct permissions for overrides', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = createUserManagementActor('admin');
    $employeeRole = Role::findByName('employee', 'api');
    $managerRole = Role::findByName('manager', 'api');
    $adminRole = Role::findByName('admin', 'api');
    $employee = createStandaloneEmployee([
        'employee_code' => 'EMP000501',
        'first_name' => 'Default',
        'last_name' => 'Employee',
        'email' => 'default.employee.profile@example.com',
    ]);
    $manager = createStandaloneEmployee([
        'employee_code' => 'EMP000502',
        'first_name' => 'Default',
        'last_name' => 'Manager',
        'email' => 'default.manager.profile@example.com',
    ]);

    Passport::actingAs($admin);

    $employeeUserId = $this->postJson('/api/users', [
        'name' => 'Default Employee',
        'email' => 'default.employee@example.com',
        'password' => 'Password123!',
        'employee_id' => $employee->id,
        'role_ids' => [$employeeRole->id],
    ])
        ->assertCreated()
        ->assertJsonPath('roles.0.name', 'employee')
        ->json('id');

    $managerUserId = $this->postJson('/api/users', [
        'name' => 'Default Manager',
        'email' => 'default.manager@example.com',
        'password' => 'Password123!',
        'employee_id' => $manager->id,
        'role_ids' => [$managerRole->id],
    ])
        ->assertCreated()
        ->assertJsonPath('roles.0.name', 'manager')
        ->json('id');

    $adminUserId = $this->postJson('/api/users', [
        'name' => 'Default Admin',
        'email' => 'default.admin@example.com',
        'password' => 'Password123!',
        'role_ids' => [$adminRole->id],
    ])
        ->assertCreated()
        ->assertJsonPath('roles.0.name', 'admin')
        ->json('id');

    $employeeAccess = $this->getJson("/api/users/{$employeeUserId}/access")
        ->assertOk()
        ->assertJsonPath('direct_permissions', [])
        ->json();

    expect($employeeAccess['role_permissions'])
        ->toContain(PermissionName::OvertimeRequestViewSelf->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value)
        ->not->toContain(PermissionName::OvertimeApproveManager->value)
        ->and($employeeAccess['effective_permissions'])
        ->toContain(PermissionName::OvertimeRequestViewSelf->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value);

    $managerAccess = $this->getJson("/api/users/{$managerUserId}/access")
        ->assertOk()
        ->assertJsonPath('direct_permissions', [])
        ->json();

    expect($managerAccess['role_permissions'])
        ->toContain(PermissionName::OvertimeApproveManager->value)
        ->and($managerAccess['effective_permissions'])
        ->toContain(PermissionName::OvertimeApproveManager->value);

    $adminAccess = $this->getJson("/api/users/{$adminUserId}/access")
        ->assertOk()
        ->assertJsonPath('direct_permissions', [])
        ->json();

    expect($adminAccess['role_permissions'])
        ->toContain(PermissionName::OvertimeRequestViewAny->value)
        ->not->toContain(PermissionName::OvertimeRequestCreate->value)
        ->not->toContain(PermissionName::OvertimeRequestCancel->value)
        ->not->toContain(PermissionName::OvertimeApproveManager->value)
        ->and($adminAccess['effective_permissions'])
        ->toContain(PermissionName::OvertimeRequestViewAny->value)
        ->not->toContain(PermissionName::OvertimeRequestCreate->value)
        ->not->toContain(PermissionName::OvertimeRequestCancel->value)
        ->not->toContain(PermissionName::OvertimeApproveManager->value);

    $managerUser = User::query()->findOrFail($managerUserId);

    Passport::actingAs($managerUser);

    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('roles.0.name', 'manager')
        ->assertJsonFragment([
            'permission_names' => collect(Role::defaultPermissionNamesFor('manager'))->sort()->values()->all(),
        ]);

    Passport::actingAs($admin);

    $overrideResponse = $this->patchJson("/api/users/{$managerUserId}/permissions", [
        'permissions' => [PermissionName::AuditLogView->value],
    ])
        ->assertOk()
        ->assertJsonPath('direct_permissions.0', PermissionName::AuditLogView->value);

    expect($overrideResponse->json('effective_permissions'))
        ->toContain(PermissionName::OvertimeApproveManager->value)
        ->toContain(PermissionName::AuditLogView->value);
});

it('rejects assigning more than one role when creating or updating a user', function () {
    $admin = createUserManagementActor('admin');
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    $hrRole = Role::query()->firstOrCreate(
        ['name' => 'hr'],
        ['description' => 'HR'],
    );
    $employee = createStandaloneEmployee([
        'employee_code' => 'EMP000303',
    ]);
    $managedUser = User::factory()->create([
        'name' => 'Existing User',
        'email' => 'existing.user@example.com',
    ]);

    Passport::actingAs($admin);

    $this->postJson('/api/users', [
        'name' => 'Account User',
        'email' => 'multi.role.user@example.com',
        'password' => 'Password123!',
        'employee_id' => $employee->id,
        'role_ids' => [$employeeRole->id, $hrRole->id],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_ids']);

    $this->putJson("/api/users/{$managedUser->id}", [
        'name' => 'Existing User',
        'email' => 'existing.user@example.com',
        'role_ids' => [$employeeRole->id, $hrRole->id],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_ids']);
});

it('allows admin to create and update standalone users without employee links', function () {
    $admin = createUserManagementActor('admin');
    $adminRole = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Admin'],
    );

    Passport::actingAs($admin);

    $createResponse = $this->postJson('/api/users', [
        'name' => 'System Admin',
        'email' => 'system.admin@example.com',
        'password' => 'Password123!',
        'role_ids' => [$adminRole->id],
    ]);

    $userId = $createResponse->json('id');

    $createResponse
        ->assertCreated()
        ->assertJsonPath('name', 'System Admin')
        ->assertJsonPath('employee_id', null)
        ->assertJsonPath('roles.0.name', 'admin')
        ->assertJsonMissingPath('employee.id');

    $this->putJson("/api/users/{$userId}", [
        'name' => 'System Admin Updated',
        'email' => 'system.admin.updated@example.com',
        'role_ids' => [$adminRole->id],
    ])
        ->assertOk()
        ->assertJsonPath('name', 'System Admin Updated')
        ->assertJsonPath('employee_id', null)
        ->assertJsonPath('roles.0.name', 'admin')
        ->assertJsonMissingPath('employee.id');

    $user = User::query()->with(['roles', 'employee'])->findOrFail($userId);

    expect($user->employee)->toBeNull()
        ->and($user->roles->pluck('name')->all())->toBe(['admin']);
});

it('deletes managed users and unlinks their employee', function () {
    $admin = createUserManagementActor('admin');
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee'],
    );
    $managedUser = createManagedUser(
        [$employeeRole],
        ['name' => 'Delete Me', 'email' => 'delete.me@example.com'],
        ['employee_code' => 'EMP000401'],
    );

    Passport::actingAs($admin);

    $this->deleteJson("/api/users/{$managedUser->id}")
        ->assertNoContent();

    expect(User::query()->find($managedUser->id))->toBeNull()
        ->and($managedUser->employee?->fresh()?->user_id)->toBeNull();
});

it('forbids non admin users from managing users', function () {
    $hr = createUserManagementActor('hr');

    Passport::actingAs($hr);

    $this->getJson('/api/users')->assertForbidden();
    $this->getJson('/api/users/roles')->assertForbidden();
    $this->getJson('/api/permissions')->assertForbidden();
    $this->getJson("/api/users/{$hr->id}/access")->assertForbidden();
    $this->patchJson("/api/users/{$hr->id}/roles", ['roles' => ['employee']])->assertForbidden();
    $this->patchJson("/api/users/{$hr->id}/permissions", ['permissions' => [PermissionName::AuditLogView->value]])->assertForbidden();
});

function createUserManagementActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles.permissions');
}

/**
 * @param  array<int, Role>  $roles
 * @param  array<string, mixed>  $userOverrides
 * @param  array<string, mixed>  $employeeOverrides
 */
function createManagedUser(array $roles, array $userOverrides = [], array $employeeOverrides = []): User
{
    $user = User::factory()->create($userOverrides);
    $employee = createStandaloneEmployee($employeeOverrides + ['user_id' => $user->id]);

    $user->roles()->sync(collect($roles)->pluck('id')->all());
    $user->setRelation('employee', $employee);

    return $user->fresh(['employee', 'roles.permissions', 'permissions']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createStandaloneEmployee(array $overrides = []): Employee
{
    $department = Department::query()->create([
        'name' => fake()->unique()->company(),
    ]);
    $position = Position::query()->create([
        'title' => fake()->unique()->jobTitle(),
    ]);
    $userId = array_key_exists('user_id', $overrides)
        ? $overrides['user_id']
        : null;

    return Employee::query()->create(array_merge([
        'user_id' => $userId,
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
