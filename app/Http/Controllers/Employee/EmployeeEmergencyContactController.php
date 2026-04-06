<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeEmergencyContactRequest;
use App\Http\Requests\Employee\UpdateEmployeeEmergencyContactRequest;
use App\Http\Resources\EmployeeEmergencyContactResource;
use App\Services\Employee\EmployeeEmergencyContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EmployeeEmergencyContactController extends Controller
{
    public function __construct(private EmployeeEmergencyContactService $employeeEmergencyContactService) {}

    public function index(int $id): AnonymousResourceCollection
    {
        return EmployeeEmergencyContactResource::collection(
            $this->employeeEmergencyContactService->index($id)
        );
    }

    public function store(StoreEmployeeEmergencyContactRequest $request, int $id): JsonResponse
    {
        return (new EmployeeEmergencyContactResource(
            $this->employeeEmergencyContactService->create($id, $request->validated())
        ))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeeEmergencyContactRequest $request, int $id, int $contactId): EmployeeEmergencyContactResource
    {
        return new EmployeeEmergencyContactResource(
            $this->employeeEmergencyContactService->update($id, $contactId, $request->validated())
        );
    }

    public function destroy(int $id, int $contactId): Response
    {
        $this->employeeEmergencyContactService->delete($id, $contactId);

        return response()->noContent();
    }
}
