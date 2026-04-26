<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('migrates legacy direct permissions to standardized names and deletes legacy rows', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::query()->firstOrCreate(
        ['name' => 'admin', 'guard_name' => 'api'],
        ['description' => 'Admin']
    );
    $hrRole = Role::query()->firstOrCreate(
        ['name' => 'hr', 'guard_name' => 'api'],
        ['description' => 'HR']
    );

    $adminRole->syncPermissions([
        PermissionName::AttendanceExport->value,
        PermissionName::AttendanceRecord->value,
        PermissionName::AttendanceViewAny->value,
        PermissionName::EmployeeManage->value,
        PermissionName::LeaveApproveHr->value,
        PermissionName::UserManage->value,
    ]);
    $hrRole->syncPermissions([
        PermissionName::AttendanceExport->value,
        PermissionName::AttendanceRecord->value,
        PermissionName::AttendanceViewAny->value,
    ]);

    $legacyPermissions = [
        'approve_leave_as_manager',
        'attendance.correction.review.queue',
        'attendance.export.self',
        'attendance.record.self',
        'leave.request.review.hr',
        'leave.request.review.manager',
        'manage_departments',
        'manage_employees',
        'manage_leave',
        'manage_payroll',
        'manage_users',
        'view_payroll',
    ];

    foreach ($legacyPermissions as $legacyPermission) {
        Permission::query()->updateOrCreate(
            ['name' => $legacyPermission, 'guard_name' => 'api'],
            ['description' => 'Legacy permission']
        );
    }

    $alice = User::factory()->create([
        'name' => 'Alice CEO',
        'email' => 'alice.ceo@example.com',
    ]);
    $henry = User::factory()->create([
        'name' => 'Henry Recruiter',
        'email' => 'henry.hr@example.com',
    ]);

    $alice->syncRoles([$adminRole]);
    $henry->syncRoles([$hrRole]);

    $alice->syncPermissions([
        'approve_leave_as_manager',
        'attendance.correction.review.queue',
        'attendance.export.self',
        'attendance.record.self',
        'leave.request.review.hr',
        'leave.request.review.manager',
        'manage_departments',
        'manage_employees',
        'manage_leave',
        'manage_payroll',
        'manage_users',
        'view_payroll',
    ]);
    $henry->syncPermissions([
        'attendance.correction.review.queue',
        'attendance.export.self',
        'attendance.record.self',
    ]);

    $migration = require database_path('migrations/2026_04_21_232644_cleanup_legacy_permissions_and_standardize_direct_assignments.php');
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $alice = $alice->fresh();
    $henry = $henry->fresh();

    expect(Permission::query()->whereNotIn('name', PermissionName::values())->exists())
        ->toBeFalse()
        ->and($alice->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe([PermissionName::LeaveApproveManager->value])
        ->and($henry->getDirectPermissions()->pluck('name')->all())
        ->toBe([])
        ->and($alice->can(PermissionName::LeaveApproveManager->value))
        ->toBeTrue()
        ->and($alice->can(PermissionName::LeaveApproveHr->value))
        ->toBeTrue()
        ->and($alice->can(PermissionName::AttendanceExport->value))
        ->toBeTrue()
        ->and($alice->can(PermissionName::AttendanceRecord->value))
        ->toBeTrue()
        ->and($alice->can(PermissionName::UserManage->value))
        ->toBeTrue()
        ->and($alice->can(PermissionName::EmployeeManage->value))
        ->toBeTrue()
        ->and($henry->can(PermissionName::AttendanceExport->value))
        ->toBeTrue()
        ->and($henry->can(PermissionName::AttendanceRecord->value))
        ->toBeTrue()
        ->and($henry->can(PermissionName::AttendanceViewAny->value))
        ->toBeTrue();
});
