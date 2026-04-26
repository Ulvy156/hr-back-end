<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\IndexOwnPayslipRequest;
use App\Http\Resources\Payroll\OwnPayslipResource;
use App\Models\PayrollItem;
use App\Services\Payroll\OwnPayslipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollPayslipController extends Controller
{
    public function __construct(private OwnPayslipService $ownPayslipService) {}

    public function index(IndexOwnPayslipRequest $request): AnonymousResourceCollection
    {
        return OwnPayslipResource::collection(
            $this->ownPayslipService->paginate(
                $request->user('api') ?? $request->user(),
                $request->validated(),
            )
        );
    }

    public function show(Request $request, PayrollItem $payrollItem): JsonResponse
    {
        return OwnPayslipResource::make(
            $this->ownPayslipService->find(
                $request->user('api') ?? $request->user(),
                $payrollItem,
            )
        )->response();
    }
}
