<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('migrates hr approvers onto hr and removes the legacy role', function () {
    $legacyRole = Role::query()->create([
        'name' => 'hr_approver',
        'description' => 'Legacy HR approver role',
        'guard_name' => 'api',
    ]);
    $user = User::factory()->create([
        'email' => 'legacy.hr.approver@example.com',
    ]);
    $user->assignRole($legacyRole);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_04_20_212138_remove_hr_approver_role.php');
    $migration->up();

    $user->refresh();
    $hrRole = Role::query()->where('name', 'hr')->firstOrFail();

    expect(Role::query()->where('name', 'hr_approver')->exists())->toBeFalse()
        ->and($user->hasRole('hr'))->toBeTrue()
        ->and($user->hasRole('hr_approver'))->toBeFalse()
        ->and($hrRole->permissions()->pluck('name')->all())->toContain('leave.approve.hr');
});
