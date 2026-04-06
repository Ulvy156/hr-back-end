<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogIndexRequest;
use App\Services\Audit\AuditLogExportService;
use App\Services\Audit\AuditLogQueryService;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditLogController extends Controller
{
    public function __construct(
        private AuditLogQueryService $auditLogQueryService,
        private AuditLogExportService $auditLogExportService,
    ) {}

    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        return response()->json(
            $this->auditLogQueryService->paginate($request->validated())
        );
    }

    public function show(Activity $activity): JsonResponse
    {
        return response()->json(
            $this->auditLogQueryService->find($activity)
        );
    }

    public function exportExcel(AuditLogIndexRequest $request): BinaryFileResponse
    {
        $export = $this->auditLogExportService->exportExcel($request->validated());

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => $export['content_type']]
        )->deleteFileAfterSend(true);
    }
}
