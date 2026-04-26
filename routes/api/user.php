<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('users')->group(function (): void {
    Route::middleware('permission:'.PermissionName::RoleView->value)->get('roles', [UserController::class, 'roles']);
    Route::middleware('permission:'.PermissionName::UserView->value)->get('{user}/access', [UserController::class, 'access']);
    Route::middleware('permission:'.PermissionName::UserRoleAssign->value)->patch('{user}/roles', [UserController::class, 'syncRoles']);
    Route::middleware('permission:'.PermissionName::UserPermissionAssign->value)->patch('{user}/permissions', [UserController::class, 'syncPermissions']);
    Route::middleware('permission:'.PermissionName::UserRoleAssign->value.'|'.PermissionName::UserPermissionAssign->value)
        ->patch('{user}/access', [UserController::class, 'syncAccess']);

    Route::middleware('permission:'.PermissionName::UserManage->value)->group(function (): void {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('{user}', [UserController::class, 'show']);
        Route::put('{user}', [UserController::class, 'update']);
        Route::delete('{user}', [UserController::class, 'destroy']);
        Route::post('{user}/reset-password', [AuthController::class, 'resetPassword']);
    });
});
