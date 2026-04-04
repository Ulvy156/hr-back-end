<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Services\Employee\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employeeService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        return response()->json(
            $this->employeeService->paginate($perPage)
        );
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(
            $this->employeeService->find($id)
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        return response()->json(
            $this->employeeService->create($request->validated()),
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateEmployeeRequest $request, int $id): JsonResponse
    {
        return response()->json(
            $this->employeeService->update($id, $request->validated())
        );
    }

    public function destroy(int $id): Response
    {
        $this->employeeService->delete($id);

        return response()->noContent();
    }

    public function getByManager(int $managerId): JsonResponse
    {
        return response()->json(
            $this->employeeService->getByManager($managerId)
        );
    }
}
