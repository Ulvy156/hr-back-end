<?php

namespace App\Http\Controllers;

use App\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PermissionCatalog::all());
    }
}
