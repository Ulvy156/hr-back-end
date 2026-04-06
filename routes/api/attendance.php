<?php

use App\Http\Controllers\Attendance\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:api',
    'role:employee,hr,admin',
])->prefix('attendance')->group(function (): void {
    Route::get('export/pdf', [AttendanceController::class, 'exportPdf']);
    Route::get('export/excel', [AttendanceController::class, 'exportExcel']);
});

Route::middleware(['auth:api', 'role:employee,hr'])->prefix('attendance')->group(function (): void {
    Route::post('scan', [AttendanceController::class, 'scan']);
    Route::post('check-in', [AttendanceController::class, 'checkIn']);
    Route::post('check-out', [AttendanceController::class, 'checkOut']);
    Route::prefix('me')->group(function (): void {
        Route::get('today', [AttendanceController::class, 'myToday']);
        Route::get('history', [AttendanceController::class, 'myHistory']);
        Route::get('summary', [AttendanceController::class, 'mySummary']);
        Route::post('correction-request', [AttendanceController::class, 'submitCorrectionRequest']);
        Route::post('missing-request', [AttendanceController::class, 'submitMissingAttendanceRequest']);
    });
});

Route::middleware(['auth:api', 'role:hr,admin'])->prefix('attendance')->group(function (): void {
    Route::get('summary/today', [AttendanceController::class, 'todaySummary']);
    Route::get('summary/monthly', [AttendanceController::class, 'monthlySummary']);
    Route::get('correction-requests', [AttendanceController::class, 'correctionRequests']);
    Route::get('/', [AttendanceController::class, 'index']);
    Route::get('{attendance}', [AttendanceController::class, 'show']);
});

Route::middleware(['auth:api', 'role:hr'])->prefix('attendance')->group(function (): void {
    Route::post('manual', [AttendanceController::class, 'storeManual']);
    Route::get('outage-recovery/preview', [AttendanceController::class, 'outageRecoveryPreview']);
    Route::post('outage-recovery/apply', [AttendanceController::class, 'outageRecoveryApply']);
    Route::patch('{attendance}/correct', [AttendanceController::class, 'correct']);
    Route::patch('correction-requests/{attendanceCorrectionRequest}', [AttendanceController::class, 'reviewCorrectionRequest']);
});

Route::middleware(['auth:api', 'role:admin'])->prefix('attendance')->group(function (): void {
    Route::get('audit/logs', [AttendanceController::class, 'auditLogs']);
});
