<?php

use App\Models\Permission;
use App\Models\Role;
use App\PermissionName;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('seeds the requested roles and permissions idempotently', function () {
    Permission::query()->create([
        'name' => 'manage_payroll',
        'description' => 'Legacy payroll access',
        'guard_name' => 'api',
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $expectedPermissions = collect(PermissionName::values())
        ->sort()
        ->values()
        ->all();

    $seededPermissions = Permission::query()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($seededPermissions)
        ->toBe($expectedPermissions)
        ->and(Permission::query()->where('name', 'manage_payroll')->exists())
        ->toBeFalse()
        ->and(Role::query()->whereIn('name', ['admin', 'employee', 'hr', 'hr_head', 'hr_manager', 'manager'])->orderBy('name')->pluck('name')->all())
        ->toBe(['admin', 'employee', 'hr', 'hr_head', 'hr_manager', 'manager']);

    $employeePermissions = Role::findByName('employee', 'api')
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $managerPermissions = Role::findByName('manager', 'api')
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $hrPermissions = Role::findByName('hr', 'api')
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $hrHeadPermissions = Role::findByName('hr_head', 'api')
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $hrManagerPermissions = Role::findByName('hr_manager', 'api')
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $adminPermissions = Role::findByName('admin', 'api')
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($employeePermissions)->toBe(collect(Role::defaultPermissionNamesFor('employee'))->sort()->values()->all())
        ->and($managerPermissions)->toBe(collect(Role::defaultPermissionNamesFor('manager'))->sort()->values()->all())
        ->and($hrPermissions)->toBe(collect(Role::defaultPermissionNamesFor('hr'))->sort()->values()->all())
        ->and($hrHeadPermissions)->toBe(collect(Role::defaultPermissionNamesFor('hr_head'))->sort()->values()->all())
        ->and($hrManagerPermissions)->toBe(collect(Role::defaultPermissionNamesFor('hr_manager'))->sort()->values()->all())
        ->and($adminPermissions)->toBe(collect(Role::defaultPermissionNamesFor('admin'))->sort()->values()->all());
});

it('assigns payroll permissions to the intended default roles by hr level', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $allPayrollPermissions = [
        PermissionName::PayrollSalaryView->value,
        PermissionName::PayrollSalaryManage->value,
        PermissionName::PayrollRunView->value,
        PermissionName::PayrollRunGenerate->value,
        PermissionName::PayrollRunRegenerate->value,
        PermissionName::PayrollRunApprove->value,
        PermissionName::PayrollRunMarkPaid->value,
        PermissionName::PayrollRunCancel->value,
        PermissionName::PayrollPayslipViewOwn->value,
        PermissionName::PayrollPayslipViewAny->value,
        PermissionName::PayrollExport->value,
    ];

    expect(Permission::query()->whereIn('name', $allPayrollPermissions)->count())
        ->toBe(count($allPayrollPermissions));

    $hrPermissions = Role::findByName('hr', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $employeePermissions = Role::findByName('employee', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $hrHeadPermissions = Role::findByName('hr_head', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $hrManagerPermissions = Role::findByName('hr_manager', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $adminPermissions = Role::findByName('admin', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $managerPermissions = Role::findByName('manager', 'api')->permissions()->orderBy('name')->pluck('name')->all();

    expect($hrPermissions)
        ->toContain(PermissionName::LeaveTypeManage->value)
        ->toContain(PermissionName::HolidayManage->value)
        ->toContain(PermissionName::PayrollRunView->value)
        ->toContain(PermissionName::PayrollSalaryView->value)
        ->toContain(PermissionName::PayrollSalaryManage->value)
        ->toContain(PermissionName::PayrollExport->value)
        ->toContain(PermissionName::PayrollRunGenerate->value)
        ->toContain(PermissionName::PayrollRunRegenerate->value)
        ->toContain(PermissionName::PayrollRunApprove->value)
        ->toContain(PermissionName::PayrollRunMarkPaid->value)
        ->toContain(PermissionName::PayrollRunCancel->value)
        ->toContain(PermissionName::PayrollPayslipViewAny->value)
        ->not->toContain(PermissionName::PayrollPayslipViewOwn->value);

    expect($hrHeadPermissions)->toBe($hrPermissions)
        ->and($hrManagerPermissions)->toBe($hrPermissions);

    expect($employeePermissions)
        ->toContain(PermissionName::PayrollPayslipViewOwn->value)
        ->not->toContain(PermissionName::PayrollRunView->value)
        ->not->toContain(PermissionName::PayrollExport->value);

    expect($managerPermissions)
        ->not->toContain(PermissionName::PayrollSalaryView->value)
        ->not->toContain(PermissionName::PayrollSalaryManage->value)
        ->not->toContain(PermissionName::PayrollRunView->value)
        ->not->toContain(PermissionName::PayrollRunGenerate->value)
        ->not->toContain(PermissionName::PayrollRunRegenerate->value)
        ->not->toContain(PermissionName::PayrollRunApprove->value)
        ->not->toContain(PermissionName::PayrollRunMarkPaid->value)
        ->not->toContain(PermissionName::PayrollRunCancel->value)
        ->toContain(PermissionName::PayrollPayslipViewOwn->value)
        ->not->toContain(PermissionName::PayrollPayslipViewAny->value)
        ->not->toContain(PermissionName::PayrollExport->value);

    expect($adminPermissions)
        ->toContain(PermissionName::PayrollSalaryView->value)
        ->toContain(PermissionName::PayrollRunView->value)
        ->toContain(PermissionName::PayrollPayslipViewAny->value)
        ->toContain(PermissionName::PayrollExport->value)
        ->not->toContain(PermissionName::PayrollSalaryManage->value)
        ->not->toContain(PermissionName::PayrollRunGenerate->value)
        ->not->toContain(PermissionName::PayrollRunRegenerate->value)
        ->not->toContain(PermissionName::PayrollRunApprove->value)
        ->not->toContain(PermissionName::PayrollRunMarkPaid->value)
        ->not->toContain(PermissionName::PayrollRunCancel->value)
        ->not->toContain(PermissionName::PayrollPayslipViewOwn->value);
});

it('assigns overtime permissions with admin as view-only', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $seededOvertimePermissions = Permission::query()
        ->where('name', 'like', 'overtime.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();
    $employeePermissions = Role::findByName('employee', 'api')->permissions()->pluck('name')->all();
    $hrPermissions = Role::findByName('hr', 'api')->permissions()->pluck('name')->all();
    $managerPermissions = Role::findByName('manager', 'api')->permissions()->pluck('name')->all();
    $adminPermissions = Role::findByName('admin', 'api')->permissions()->pluck('name')->all();

    expect($seededOvertimePermissions)->toBe([
        PermissionName::OvertimeApproveManager->value,
        PermissionName::OvertimeRequestCancel->value,
        PermissionName::OvertimeRequestCreate->value,
        PermissionName::OvertimeRequestViewAny->value,
        PermissionName::OvertimeRequestViewAssigned->value,
        PermissionName::OvertimeRequestViewSelf->value,
    ]);

    expect($employeePermissions)
        ->toContain(PermissionName::OvertimeRequestViewSelf->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value)
        ->not->toContain(PermissionName::OvertimeApproveManager->value)
        ->not->toContain(PermissionName::OvertimeRequestViewAny->value);

    expect($hrPermissions)
        ->toContain(PermissionName::OvertimeRequestViewAny->value)
        ->toContain(PermissionName::OvertimeRequestViewSelf->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value)
        ->not->toContain(PermissionName::OvertimeApproveManager->value);

    expect($managerPermissions)
        ->toContain(PermissionName::OvertimeApproveManager->value)
        ->toContain(PermissionName::OvertimeRequestViewAssigned->value)
        ->toContain(PermissionName::OvertimeRequestCreate->value)
        ->toContain(PermissionName::OvertimeRequestCancel->value)
        ->toContain(PermissionName::OvertimeRequestViewSelf->value);

    expect($adminPermissions)
        ->toContain(PermissionName::OvertimeRequestViewAny->value)
        ->not->toContain(PermissionName::OvertimeRequestCreate->value)
        ->not->toContain(PermissionName::OvertimeRequestCancel->value)
        ->not->toContain(PermissionName::OvertimeApproveManager->value)
        ->not->toContain(PermissionName::OvertimeRequestViewAssigned->value)
        ->not->toContain(PermissionName::OvertimeRequestViewSelf->value);
});
