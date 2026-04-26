<?php

use App\Http\Controllers\PermissionController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'permission:'.PermissionName::PermissionView->value])
    ->get('permissions', [PermissionController::class, 'index']);
