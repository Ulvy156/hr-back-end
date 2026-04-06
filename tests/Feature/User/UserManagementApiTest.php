<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

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
        ->assertJsonPath('data.0.name', 'Visible User')
        ->assertJsonPath('data.0.employee.employee_code', 'EMP000201')
        ->assertJsonPath('data.0.employee.full_name', 'Visible Sok')
        ->assertJsonPath('data.0.roles.0.name', 'employee')
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/users/roles')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'admin')
        ->assertJsonFragment(['name' => 'employee'])
        ->assertJsonFragment(['name' => 'hr']);
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
        ->assertJsonPath('name', 'Account User')
        ->assertJsonPath('employee.id', $firstEmployee->id)
        ->assertJsonPath('roles.0.name', 'employee');

    $this->putJson("/api/users/{$userId}", [
        'name' => 'Account User Updated',
        'email' => 'account.user.updated@example.com',
        'employee_id' => $secondEmployee->id,
        'role_ids' => [$employeeRole->id, $hrRole->id],
    ])
        ->assertOk()
        ->assertJsonPath('name', 'Account User Updated')
        ->assertJsonPath('employee.id', $secondEmployee->id)
        ->assertJsonCount(2, 'roles')
        ->assertJsonFragment(['name' => 'employee'])
        ->assertJsonFragment(['name' => 'hr']);

    $user = User::query()->with(['roles', 'employee'])->findOrFail($userId);

    expect($user->employee?->id)->toBe($secondEmployee->id)
        ->and($user->roles->pluck('name')->sort()->values()->all())->toBe(['employee', 'hr'])
        ->and($firstEmployee->fresh()?->user_id)->toBeNull()
        ->and($secondEmployee->fresh()?->user_id)->toBe($userId);
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
});

function createUserManagementActor(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['description' => ucfirst($roleName)],
    );

    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh('roles');
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

    return $user->fresh(['employee', 'roles']);
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
