<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Http\Requests\Overtime\IndexOvertimeRequestRequest;
use App\Http\Requests\Overtime\ManagerApproveOvertimeRequestRequest;
use App\Http\Requests\Overtime\RejectOvertimeRequestRequest;
use App\Http\Requests\Overtime\StoreOvertimeRequestRequest;
use App\Http\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Services\Overtime\OvertimeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OvertimeRequestController extends Controller
{
    public function __construct(private OvertimeRequestService $overtimeRequestService) {}

    public function store(StoreOvertimeRequestRequest $request): JsonResponse
    {
        $result = $this->overtimeRequestService->store($request->user('api'), $request->validated());

        return response()->json([
            'message' => $result['message'],
            'data' => OvertimeRequestResource::make($result['data'])->resolve($request),
        ], Response::HTTP_CREATED);
    }

    public function index(IndexOvertimeRequestRequest $request): JsonResponse
    {
        return OvertimeRequestResource::collection(
            $this->overtimeRequestService->paginate($request->user('api'), $request->validated())
        )->response();
    }

    public function show(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        return response()->json(
            OvertimeRequestResource::make(
                $this->overtimeRequestService->show($request->user('api'), $overtimeRequest)
            )->resolve($request)
        );
    }

    public function managerApprove(
        ManagerApproveOvertimeRequestRequest $request,
        OvertimeRequest $overtimeRequest,
    ): JsonResponse {
        $result = $this->overtimeRequestService->managerApprove($request->user('api'), $overtimeRequest);

        return response()->json([
            'message' => $result['message'],
            'data' => OvertimeRequestResource::make($result['data'])->resolve($request),
        ]);
    }

    public function reject(
        RejectOvertimeRequestRequest $request,
        OvertimeRequest $overtimeRequest,
    ): JsonResponse {
        $result = $this->overtimeRequestService->reject($request->user('api'), $overtimeRequest, $request->validated());

        return response()->json([
            'message' => $result['message'],
            'data' => OvertimeRequestResource::make($result['data'])->resolve($request),
        ]);
    }

    public function cancel(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $result = $this->overtimeRequestService->cancel($request->user('api'), $overtimeRequest);

        return response()->json([
            'message' => $result['message'],
            'data' => OvertimeRequestResource::make($result['data'])->resolve($request),
        ]);
    }
}
