<?php

use App\Http\Controllers\Employee\EmployeeAddressController;
use App\Http\Controllers\Employee\EmployeeController;
use App\Http\Controllers\Employee\EmployeeEducationController;
use App\Http\Controllers\Employee\EmployeeEmergencyContactController;
use App\Http\Controllers\Employee\EmployeePositionController;
use App\Http\Controllers\Employee\LocationController;
use App\Http\Controllers\Employee\PositionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin,hr'])->get('positions', [PositionController::class, 'index']);
Route::middleware(['auth:api', 'role:admin,hr,employee'])->prefix('locations')->group(function (): void {
    Route::get('provinces', [LocationController::class, 'provinces']);
    Route::get('districts', [LocationController::class, 'districts']);
    Route::get('communes', [LocationController::class, 'communes']);
    Route::get('villages', [LocationController::class, 'villages']);
});

Route::middleware('auth:api')->prefix('employees')->group(function (): void {
    Route::middleware('role:admin,hr')->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::post('{id}/restore', [EmployeeController::class, 'restore']);
        Route::post('{id}/activate', [EmployeeController::class, 'activate']);
        Route::post('{id}/deactivate', [EmployeeController::class, 'deactivate']);
        Route::post('{id}/terminate', [EmployeeController::class, 'terminate']);
        Route::post('{id}/unterminate', [EmployeeController::class, 'unterminate']);
        Route::post('{id}/profile-photo', [EmployeeController::class, 'uploadProfilePhoto']);
        Route::get('{id}/addresses', [EmployeeAddressController::class, 'index']);
        Route::post('{id}/addresses', [EmployeeAddressController::class, 'store']);
        Route::put('{id}/addresses/{addressId}', [EmployeeAddressController::class, 'update']);
        Route::delete('{id}/addresses/{addressId}', [EmployeeAddressController::class, 'destroy']);
        Route::get('{id}/emergency-contacts', [EmployeeEmergencyContactController::class, 'index']);
        Route::post('{id}/emergency-contacts', [EmployeeEmergencyContactController::class, 'store']);
        Route::put('{id}/emergency-contacts/{contactId}', [EmployeeEmergencyContactController::class, 'update']);
        Route::delete('{id}/emergency-contacts/{contactId}', [EmployeeEmergencyContactController::class, 'destroy']);
        Route::get('{id}/educations', [EmployeeEducationController::class, 'index']);
        Route::post('{id}/educations', [EmployeeEducationController::class, 'store']);
        Route::put('{id}/educations/{educationId}', [EmployeeEducationController::class, 'update']);
        Route::delete('{id}/educations/{educationId}', [EmployeeEducationController::class, 'destroy']);
        Route::get('{id}/positions', [EmployeePositionController::class, 'index']);
        Route::post('{id}/positions', [EmployeePositionController::class, 'store']);
        Route::put('{id}/positions/{employeePositionId}', [EmployeePositionController::class, 'update']);
        Route::delete('{id}/positions/{employeePositionId}', [EmployeePositionController::class, 'destroy']);
        Route::get('manager/{managerId}', [EmployeeController::class, 'getByManager']);
        Route::match(['put', 'patch'], '{id}', [EmployeeController::class, 'update']);
        Route::delete('{id}', [EmployeeController::class, 'destroy']);
    });

    Route::middleware('role:hr')->get('export/excel', [EmployeeController::class, 'exportExcel']);
    Route::middleware('role:employee,hr,admin')->get('{id}', [EmployeeController::class, 'show']);
});
