<?php

use App\Http\Controllers\Audit\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin'])->prefix('audit-logs')->group(function (): void {
    Route::get('export/excel', [AuditLogController::class, 'exportExcel']);
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('{activity}', [AuditLogController::class, 'show']);
});
