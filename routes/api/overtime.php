<?php

use App\Http\Controllers\Overtime\OvertimeRequestController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function (): void {
    Route::post('overtime-requests', [OvertimeRequestController::class, 'store'])
        ->middleware('permission:'.PermissionName::OvertimeRequestCreate->value);
    Route::get('overtime-requests', [OvertimeRequestController::class, 'index'])
        ->middleware('permission:'
            .PermissionName::OvertimeRequestViewAny->value.'|'
            .PermissionName::OvertimeRequestViewAssigned->value.'|'
            .PermissionName::OvertimeRequestViewSelf->value);
    Route::get('overtime-requests/{overtimeRequest}', [OvertimeRequestController::class, 'show'])
        ->middleware('permission:'
            .PermissionName::OvertimeRequestViewAny->value.'|'
            .PermissionName::OvertimeRequestViewAssigned->value.'|'
            .PermissionName::OvertimeRequestViewSelf->value);
    Route::post('overtime-requests/{overtimeRequest}/manager-approve', [OvertimeRequestController::class, 'managerApprove'])
        ->middleware('permission:'.PermissionName::OvertimeApproveManager->value);
    Route::post('overtime-requests/{overtimeRequest}/reject', [OvertimeRequestController::class, 'reject'])
        ->middleware('permission:'.PermissionName::OvertimeApproveManager->value);
    Route::post('overtime-requests/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel'])
        ->middleware('permission:'.PermissionName::OvertimeRequestCancel->value);
});
