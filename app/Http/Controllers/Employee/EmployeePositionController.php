<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeePositionRequest;
use App\Http\Requests\Employee\UpdateEmployeePositionRequest;
use App\Http\Resources\EmployeePositionResource;
use App\Services\Employee\EmployeePositionCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EmployeePositionController extends Controller
{
    public function __construct(private EmployeePositionCrudService $employeePositionCrudService) {}

    public function index(int $id): AnonymousResourceCollection
    {
        return EmployeePositionResource::collection(
            $this->employeePositionCrudService->index($id)
        );
    }

    public function store(StoreEmployeePositionRequest $request, int $id): JsonResponse
    {
        return (new EmployeePositionResource(
            $this->employeePositionCrudService->create($id, $request->validated())
        ))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeePositionRequest $request, int $id, int $employeePositionId): EmployeePositionResource
    {
        return new EmployeePositionResource(
            $this->employeePositionCrudService->update($id, $employeePositionId, $request->validated())
        );
    }

    public function destroy(int $id, int $employeePositionId): Response
    {
        $this->employeePositionCrudService->delete($id, $employeePositionId);

        return response()->noContent();
    }
}
