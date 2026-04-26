<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps one primary role and preserves removed-role permissions as direct permissions', function () {
    $managerRole = Role::query()->firstOrCreate(
        ['name' => 'manager'],
        ['description' => 'Manager', 'guard_name' => 'api'],
    );
    $hrRole = Role::query()->firstOrCreate(
        ['name' => 'hr'],
        ['description' => 'HR', 'guard_name' => 'api'],
    );

    $managerPermission = Permission::query()->firstOrCreate(
        ['name' => 'leave.request.view.assigned'],
        ['description' => 'View assigned leave requests', 'guard_name' => 'api'],
    );
    $hrPermission = Permission::query()->firstOrCreate(
        ['name' => 'leave.approve.hr'],
        ['description' => 'Approve leave at HR stage', 'guard_name' => 'api'],
    );

    $managerRole->givePermissionTo($managerPermission);
    $hrRole->givePermissionTo($hrPermission);

    $user = User::factory()->create([
        'email' => 'multi.role@example.com',
    ]);
    $user->assignRole($managerRole);
    $user->assignRole($hrRole);

    $migration = require base_path('database/migrations/2026_04_20_215852_enforce_single_role_per_user.php');
    $migration->up();

    $user->refresh()->load(['roles', 'permissions']);

    expect($user->getRoleNames()->all())->toBe(['hr'])
        ->and($user->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe(['leave.request.view.assigned'])
        ->and($user->can('leave.approve.hr'))->toBeTrue()
        ->and($user->can('leave.request.view.assigned'))->toBeTrue();
});
