<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveTypeResource;
use App\Services\Leave\LeaveTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController extends Controller
{
    public function __construct(
        private LeaveTypeService $leaveTypeService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection(
            $this->leaveTypeService->listActive($request->user('api'))
        );
    }

    public function balances(Request $request): AnonymousResourceCollection
    {
        return LeaveBalanceResource::collection(
            $this->leaveTypeService->currentBalances($request->user('api'))
        );
    }
}
