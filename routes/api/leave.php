<?php

use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Http\Controllers\Leave\PublicHolidayController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('leave')->group(function (): void {
    Route::get('types', [LeaveTypeController::class, 'index']);
    Route::get('me/balances', [LeaveTypeController::class, 'balances']);
    Route::get('public-holidays', [PublicHolidayController::class, 'index']);
    Route::post('requests', [LeaveRequestController::class, 'store']);
    Route::get('me/requests', [LeaveRequestController::class, 'myHistory']);
    Route::get('requests', [LeaveRequestController::class, 'index']);
    Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
    Route::patch('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
    Route::patch('requests/{leaveRequest}/manager-review', [LeaveRequestController::class, 'managerReview']);
    Route::patch('requests/{leaveRequest}/hr-review', [LeaveRequestController::class, 'hrReview']);
});
