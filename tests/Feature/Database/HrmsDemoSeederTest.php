<?php

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use Database\Seeders\HrmsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('seeds the demo employee hierarchy with dedicated leave approvers', function () {
    $this->seed(HrmsDemoSeeder::class);

    $headOfHr = Employee::query()->where('email', 'helen.hr@example.com')->firstOrFail();
    $director = Employee::query()->where('email', 'derek.director@example.com')->firstOrFail();
    $operationsManager = Employee::query()->where('email', 'mark.ops@example.com')->firstOrFail();
    $normalEmployee = Employee::query()->where('email', 'emma.employee@example.com')->firstOrFail();

    expect(Employee::query()->count())->toBe(10)
        ->and($headOfHr->leave_approver_id)->toBe($director->id)
        ->and($operationsManager->leave_approver_id)->toBe($director->id)
        ->and($normalEmployee->leave_approver_id)->toBeNull();
});

it('seeds payroll permissions for the intended demo hr hierarchy', function () {
    $this->seed(HrmsDemoSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $payrollPermissions = [
        PermissionName::PayrollSalaryView->value,
        PermissionName::PayrollSalaryManage->value,
        PermissionName::PayrollRunView->value,
        PermissionName::PayrollRunGenerate->value,
        PermissionName::PayrollRunRegenerate->value,
        PermissionName::PayrollRunApprove->value,
        PermissionName::PayrollRunMarkPaid->value,
        PermissionName::PayrollRunCancel->value,
        PermissionName::PayrollPayslipViewAny->value,
        PermissionName::PayrollPayslipViewOwn->value,
        PermissionName::PayrollExport->value,
    ];

    expect(Permission::query()->whereIn('name', $payrollPermissions)->count())
        ->toBe(count($payrollPermissions));

    $hrPermissions = Role::findByName('hr', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $hrHeadPermissions = Role::findByName('hr_head', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $hrManagerPermissions = Role::findByName('hr_manager', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $employeePermissions = Role::findByName('employee', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $managerPermissions = Role::findByName('manager', 'api')->permissions()->orderBy('name')->pluck('name')->all();
    $adminPermissions = Role::findByName('admin', 'api')->permissions()->orderBy('name')->pluck('name')->all();

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

    $headOfHrUser = User::query()->where('email', 'helen.hr@example.com')->firstOrFail();
    $regularHrUser = User::query()->where('email', 'henry.hr@example.com')->firstOrFail();

    expect($headOfHrUser->roles->pluck('name')->all())->toBe(['hr_head'])
        ->and($headOfHrUser->getAllPermissions()->pluck('name')->all())
        ->toContain(PermissionName::PayrollSalaryView->value)
        ->toContain(PermissionName::PayrollSalaryManage->value)
        ->toContain(PermissionName::PayrollRunView->value)
        ->toContain(PermissionName::PayrollRunGenerate->value)
        ->toContain(PermissionName::PayrollRunRegenerate->value)
        ->toContain(PermissionName::PayrollRunApprove->value)
        ->toContain(PermissionName::PayrollRunMarkPaid->value)
        ->toContain(PermissionName::PayrollRunCancel->value)
        ->toContain(PermissionName::PayrollExport->value)
        ->toContain(PermissionName::PayrollPayslipViewAny->value)
        ->not->toContain(PermissionName::PayrollPayslipViewOwn->value);

    expect($regularHrUser->roles->pluck('name')->all())->toBe(['hr'])
        ->and($regularHrUser->getAllPermissions()->pluck('name')->all())
        ->toContain(PermissionName::PayrollRunView->value)
        ->toContain(PermissionName::PayrollSalaryView->value)
        ->toContain(PermissionName::PayrollSalaryManage->value)
        ->toContain(PermissionName::PayrollExport->value)
        ->toContain(PermissionName::PayrollRunGenerate->value)
        ->toContain(PermissionName::PayrollRunRegenerate->value)
        ->toContain(PermissionName::PayrollRunApprove->value)
        ->toContain(PermissionName::PayrollRunCancel->value)
        ->toContain(PermissionName::PayrollRunMarkPaid->value)
        ->toContain(PermissionName::PayrollPayslipViewAny->value);
});
