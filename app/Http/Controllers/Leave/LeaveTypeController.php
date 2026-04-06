<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveTypeResource;
use App\Services\Leave\LeaveTypeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController extends Controller
{
    public function __construct(
        private LeaveTypeService $leaveTypeService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection(
            $this->leaveTypeService->listActive()
        );
    }
}
