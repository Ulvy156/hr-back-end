<?php

use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Http\Controllers\Leave\PublicHolidayController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('leave')->group(function (): void {
    Route::get('types', [LeaveTypeController::class, 'index'])
        ->middleware('permission:'.PermissionName::LeaveTypeView->value);
    Route::get('me/balances', [LeaveTypeController::class, 'balances'])
        ->middleware('permission:'.PermissionName::LeaveBalanceViewSelf->value);
    Route::get('public-holidays', [PublicHolidayController::class, 'index'])
        ->middleware('permission:'.PermissionName::LeaveTypeView->value);
    Route::post('requests', [LeaveRequestController::class, 'store'])
        ->middleware('permission:'.PermissionName::LeaveRequestCreate->value);
    Route::get('me/requests', [LeaveRequestController::class, 'myHistory'])
        ->middleware('permission:'.PermissionName::LeaveRequestViewSelf->value);
    Route::get('requests', [LeaveRequestController::class, 'index'])
        ->middleware('permission:'.PermissionName::LeaveRequestViewAny->value.'|'.PermissionName::LeaveRequestViewAssigned->value);
    Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show'])
        ->middleware('permission:'.PermissionName::LeaveRequestViewSelf->value.'|'.PermissionName::LeaveRequestViewAny->value.'|'.PermissionName::LeaveRequestViewAssigned->value);
    Route::patch('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
        ->middleware('permission:'.PermissionName::LeaveRequestCancelSelf->value);
    Route::patch('requests/{leaveRequest}/manager-review', [LeaveRequestController::class, 'managerReview'])
        ->middleware('permission:'.PermissionName::LeaveApproveManager->value);
    Route::patch('requests/{leaveRequest}/hr-review', [LeaveRequestController::class, 'hrReview'])
        ->middleware('permission:'.PermissionName::LeaveApproveHr->value);
});
