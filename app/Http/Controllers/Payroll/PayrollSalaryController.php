<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\IndexEmployeeSalaryRequest;
use App\Http\Requests\Payroll\StoreEmployeeSalaryRequest;
use App\Http\Requests\Payroll\UpdateEmployeeSalaryRequest;
use App\Http\Resources\Payroll\EmployeeSalaryResource;
use App\Models\EmployeeSalary;
use App\Services\Payroll\EmployeeSalaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PayrollSalaryController extends Controller
{
    public function __construct(private EmployeeSalaryService $employeeSalaryService) {}

    public function index(IndexEmployeeSalaryRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        return EmployeeSalaryResource::collection(
            $this->employeeSalaryService->paginate(
                $validated,
                (int) ($validated['per_page'] ?? 15),
                $request->user('api') ?? $request->user(),
            )
        );
    }

    public function store(StoreEmployeeSalaryRequest $request): JsonResponse
    {
        return EmployeeSalaryResource::make(
            $this->employeeSalaryService->create(
                $request->validated(),
                $request->user('api') ?? $request->user(),
            )
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeeSalaryRequest $request, EmployeeSalary $employeeSalary): EmployeeSalaryResource
    {
        return EmployeeSalaryResource::make(
            $this->employeeSalaryService->update(
                $employeeSalary,
                $request->validated(),
                $request->user('api') ?? $request->user(),
            )
        );
    }
}
