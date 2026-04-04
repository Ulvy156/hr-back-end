<?php

use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->get('dashboard', [DashboardController::class, 'show']);
