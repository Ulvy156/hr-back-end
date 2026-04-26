<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ApprovePayrollRunRequest;
use App\Http\Requests\Payroll\CancelPayrollRunRequest;
use App\Http\Requests\Payroll\IndexPayrollRunRequest;
use App\Http\Requests\Payroll\MarkPayrollRunPaidRequest;
use App\Http\Requests\Payroll\RegeneratePayrollRunRequest;
use App\Http\Requests\Payroll\StorePayrollRunRequest;
use App\Http\Resources\Payroll\PayrollRunResource;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollExportService;
use App\Services\Payroll\PayrollRunGenerationService;
use App\Services\Payroll\PayrollRunLifecycleService;
use App\Services\Payroll\PayrollRunQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollRunController extends Controller
{
    public function __construct(
        private PayrollExportService $payrollExportService,
        private PayrollRunGenerationService $payrollRunGenerationService,
        private PayrollRunLifecycleService $payrollRunLifecycleService,
        private PayrollRunQueryService $payrollRunQueryService,
    ) {}

    public function index(IndexPayrollRunRequest $request): AnonymousResourceCollection
    {
        return PayrollRunResource::collection(
            $this->payrollRunQueryService->paginate($request->validated())
        );
    }

    public function show(PayrollRun $payrollRun): PayrollRunResource
    {
        return PayrollRunResource::make(
            $this->payrollRunQueryService->find($payrollRun)
        );
    }

    public function exportExcel(Request $request, PayrollRun $payrollRun): BinaryFileResponse
    {
        $export = $this->payrollExportService->exportExcel(
            $request->user('api') ?? $request->user(),
            $payrollRun,
        );

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => $export['content_type']]
        )->deleteFileAfterSend(true);
    }

    public function store(StorePayrollRunRequest $request): JsonResponse
    {
        $preparedGeneration = $this->payrollRunGenerationService->prepareGeneration(
            $request->validated('month')
        );

        if (($preparedGeneration['blocking_message'] ?? null) !== null) {
            return response()->json([
                'message' => $preparedGeneration['blocking_message'],
                'errors' => [
                    'month' => [$preparedGeneration['blocking_message']],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($preparedGeneration['errors'] !== []) {
            return response()->json([
                'message' => 'Payroll generation failed because some employees have no valid salary.',
                'errors' => $preparedGeneration['errors'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return PayrollRunResource::make(
            $this->payrollRunGenerationService->generatePrepared(
                $preparedGeneration,
                $request->user('api') ?? $request->user(),
            )
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function approve(ApprovePayrollRunRequest $request, PayrollRun $payrollRun): PayrollRunResource
    {
        return PayrollRunResource::make(
            $this->payrollRunLifecycleService->approve(
                $payrollRun,
                $request->user('api') ?? $request->user(),
            )
        );
    }

    public function markPaid(MarkPayrollRunPaidRequest $request, PayrollRun $payrollRun): PayrollRunResource
    {
        return PayrollRunResource::make(
            $this->payrollRunLifecycleService->markPaid(
                $payrollRun,
                $request->user('api') ?? $request->user(),
            )
        );
    }

    public function cancel(CancelPayrollRunRequest $request, PayrollRun $payrollRun): PayrollRunResource
    {
        return PayrollRunResource::make(
            $this->payrollRunLifecycleService->cancel(
                $payrollRun,
                $request->user('api') ?? $request->user(),
            )
        );
    }

    public function regenerate(RegeneratePayrollRunRequest $request, PayrollRun $payrollRun): JsonResponse
    {
        $preparedGeneration = $this->payrollRunLifecycleService->prepareRegeneration($payrollRun);

        if (($preparedGeneration['blocking_message'] ?? null) !== null) {
            return response()->json([
                'message' => $preparedGeneration['blocking_message'],
                'errors' => [
                    'month' => [$preparedGeneration['blocking_message']],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($preparedGeneration['errors'] !== []) {
            return response()->json([
                'message' => 'Payroll generation failed because some employees have no valid salary.',
                'errors' => $preparedGeneration['errors'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return PayrollRunResource::make(
            $this->payrollRunLifecycleService->regeneratePrepared(
                $payrollRun,
                $preparedGeneration,
                $request->user('api') ?? $request->user(),
            )
        )->response();
    }
}
