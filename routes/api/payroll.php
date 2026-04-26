<?php

use App\Http\Controllers\Payroll\PayrollPayslipController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\Payroll\PayrollSalaryController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('payroll')->group(function (): void {
    Route::middleware('permission:'.PermissionName::PayrollPayslipViewOwn->value)
        ->prefix('me/payslips')
        ->group(function (): void {
            Route::get('/', [PayrollPayslipController::class, 'index']);
            Route::get('{payrollItem}', [PayrollPayslipController::class, 'show']);
        });

    Route::middleware('permission:'.PermissionName::PayrollRunView->value)->group(function (): void {
        Route::get('runs', [PayrollRunController::class, 'index']);
        Route::get('runs/{payrollRun}', [PayrollRunController::class, 'show']);
    });
    Route::middleware('permission:'.PermissionName::PayrollExport->value)->group(function (): void {
        Route::get('runs/{payrollRun}/export/excel', [PayrollRunController::class, 'exportExcel']);
    });
    Route::middleware('permission:'.PermissionName::PayrollRunGenerate->value)->group(function (): void {
        Route::post('runs', [PayrollRunController::class, 'store']);
    });
    Route::middleware('permission:'.PermissionName::PayrollRunRegenerate->value)->group(function (): void {
        Route::patch('runs/{payrollRun}/regenerate', [PayrollRunController::class, 'regenerate']);
    });
    Route::middleware('permission:'.PermissionName::PayrollRunApprove->value)->group(function (): void {
        Route::patch('runs/{payrollRun}/approve', [PayrollRunController::class, 'approve']);
    });
    Route::middleware('permission:'.PermissionName::PayrollRunMarkPaid->value)->group(function (): void {
        Route::patch('runs/{payrollRun}/mark-paid', [PayrollRunController::class, 'markPaid']);
    });
    Route::middleware('permission:'.PermissionName::PayrollRunCancel->value)->group(function (): void {
        Route::patch('runs/{payrollRun}/cancel', [PayrollRunController::class, 'cancel']);
    });
    Route::middleware('permission:'.PermissionName::PayrollSalaryView->value)->group(function (): void {
        Route::get('salaries', [PayrollSalaryController::class, 'index']);
    });
    Route::middleware('permission:'.PermissionName::PayrollSalaryManage->value)->group(function (): void {
        Route::post('salaries', [PayrollSalaryController::class, 'store']);
        Route::patch('salaries/{employeeSalary}', [PayrollSalaryController::class, 'update']);
    });
});
