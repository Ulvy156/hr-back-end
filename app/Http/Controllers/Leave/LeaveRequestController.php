<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\HrReviewLeaveRequestRequest;
use App\Http\Requests\Leave\IndexLeaveRequestRequest;
use App\Http\Requests\Leave\ManagerReviewLeaveRequestRequest;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveRequestService $leaveRequestService) {}

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->leaveRequestService->store($request->user('api'), $request->validated()),
            Response::HTTP_CREATED,
        );
    }

    public function myHistory(IndexLeaveRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->leaveRequestService->myHistory($request->user('api'), $request->validated())
        );
    }

    public function index(IndexLeaveRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->leaveRequestService->index($request->user('api'), $request->validated())
        );
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return response()->json(
            $this->leaveRequestService->show($request->user('api'), $leaveRequest)
        );
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        return response()->json(
            $this->leaveRequestService->cancel($request->user('api'), $leaveRequest)
        );
    }

    public function managerReview(
        ManagerReviewLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
    ): JsonResponse {
        return response()->json(
            $this->leaveRequestService->managerReview($request->user('api'), $leaveRequest, $request->validated())
        );
    }

    public function hrReview(
        HrReviewLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
    ): JsonResponse {
        return response()->json(
            $this->leaveRequestService->hrReview($request->user('api'), $leaveRequest, $request->validated())
        );
    }
}
