<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeAddressRequest;
use App\Http\Requests\Employee\UpdateEmployeeAddressRequest;
use App\Http\Resources\EmployeeAddressResource;
use App\Services\Employee\EmployeeAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EmployeeAddressController extends Controller
{
    public function __construct(private EmployeeAddressService $employeeAddressService) {}

    public function index(int $id): AnonymousResourceCollection
    {
        return EmployeeAddressResource::collection(
            $this->employeeAddressService->index($id)
        );
    }

    public function store(StoreEmployeeAddressRequest $request, int $id): JsonResponse
    {
        return (new EmployeeAddressResource(
            $this->employeeAddressService->create($id, $request->validated())
        ))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeeAddressRequest $request, int $id, int $addressId): EmployeeAddressResource
    {
        return new EmployeeAddressResource(
            $this->employeeAddressService->update($id, $addressId, $request->validated())
        );
    }

    public function destroy(int $id, int $addressId): Response
    {
        $this->employeeAddressService->delete($id, $addressId);

        return response()->noContent();
    }
}
