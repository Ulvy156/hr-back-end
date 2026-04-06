<?php

use App\Http\Controllers\Leave\LeaveTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:employee,hr,admin'])->prefix('leave')->group(function (): void {
    Route::get('types', [LeaveTypeController::class, 'index']);
});
