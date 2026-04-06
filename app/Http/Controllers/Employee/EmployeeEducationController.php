<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeEducationRequest;
use App\Http\Requests\Employee\UpdateEmployeeEducationRequest;
use App\Services\Employee\EmployeeEducationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeEducationController extends Controller
{
    public function __construct(private EmployeeEducationService $employeeEducationService) {}

    public function index(int $id): JsonResponse
    {
        return response()->json(
            $this->employeeEducationService->index($id)
        );
    }

    public function store(StoreEmployeeEducationRequest $request, int $id): JsonResponse
    {
        return response()->json(
            $this->employeeEducationService->create($id, $request->validated()),
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateEmployeeEducationRequest $request, int $id, int $educationId): JsonResponse
    {
        return response()->json(
            $this->employeeEducationService->update($id, $educationId, $request->validated())
        );
    }

    public function destroy(int $id, int $educationId): Response
    {
        $this->employeeEducationService->delete($id, $educationId);

        return response()->noContent();
    }
}
