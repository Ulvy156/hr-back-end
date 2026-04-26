<?php

use App\Http\Controllers\Attendance\AttendanceController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:api',
    'permission:'.PermissionName::AttendanceExport->value,
])->prefix('attendance')->group(function (): void {
    Route::get('export/pdf', [AttendanceController::class, 'exportPdf']);
    Route::get('export/excel', [AttendanceController::class, 'exportExcel']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceRecord->value])->prefix('attendance')->group(function (): void {
    Route::post('scan', [AttendanceController::class, 'scan']);
    Route::post('check-in', [AttendanceController::class, 'checkIn']);
    Route::post('check-out', [AttendanceController::class, 'checkOut']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceViewSelf->value])->prefix('attendance/me')->group(function (): void {
    Route::get('today', [AttendanceController::class, 'myToday']);
    Route::get('history', [AttendanceController::class, 'myHistory']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceSummarySelf->value])->prefix('attendance/me')->group(function (): void {
    Route::get('summary', [AttendanceController::class, 'mySummary']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceCorrectionRequest->value])->prefix('attendance/me')->group(function (): void {
    Route::post('correction-request', [AttendanceController::class, 'submitCorrectionRequest']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceMissingRequest->value])->prefix('attendance/me')->group(function (): void {
    Route::post('missing-request', [AttendanceController::class, 'submitMissingAttendanceRequest']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceSummaryAny->value])->prefix('attendance')->group(function (): void {
    Route::get('summary/today', [AttendanceController::class, 'todaySummary']);
    Route::get('summary/monthly', [AttendanceController::class, 'monthlySummary']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceViewAny->value])->prefix('attendance')->group(function (): void {
    Route::get('correction-requests', [AttendanceController::class, 'correctionRequests']);
    Route::get('/', [AttendanceController::class, 'index']);
    Route::get('{attendance}', [AttendanceController::class, 'show']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceManage->value])->prefix('attendance')->group(function (): void {
    Route::post('manual', [AttendanceController::class, 'storeManual']);
    Route::get('outage-recovery/preview', [AttendanceController::class, 'outageRecoveryPreview']);
    Route::post('outage-recovery/apply', [AttendanceController::class, 'outageRecoveryApply']);
    Route::patch('{attendance}/correct', [AttendanceController::class, 'correct']);
    Route::patch('correction-requests/{attendanceCorrectionRequest}', [AttendanceController::class, 'reviewCorrectionRequest']);
});

Route::middleware(['auth:api', 'permission:'.PermissionName::AttendanceAuditView->value])->prefix('attendance')->group(function (): void {
    Route::get('audit/logs', [AttendanceController::class, 'auditLogs']);
});
