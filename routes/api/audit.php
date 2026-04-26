<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'permission:'.PermissionName::AuditLogView->value])->prefix('audit-logs')->group(function (): void {
    Route::middleware('permission:'.PermissionName::AuditLogExport->value)->get('export/excel', [AuditLogController::class, 'exportExcel']);
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('{activity}', [AuditLogController::class, 'show']);
});
