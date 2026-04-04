<?php

use App\Http\Controllers\Employee\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin,hr'])->prefix('employees')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index']);
    Route::post('/', [EmployeeController::class, 'store']);
    Route::get('manager/{managerId}', [EmployeeController::class, 'getByManager']);
    Route::get('{id}', [EmployeeController::class, 'show']);
    Route::put('{id}', [EmployeeController::class, 'update']);
    Route::delete('{id}', [EmployeeController::class, 'destroy']);
});
