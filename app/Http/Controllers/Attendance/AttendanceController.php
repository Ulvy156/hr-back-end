<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceCorrectRequest;
use App\Http\Requests\Attendance\AttendanceExportRequest;
use App\Http\Requests\Attendance\AttendanceIndexRequest;
use App\Http\Requests\Attendance\AttendanceManualStoreRequest;
use App\Http\Requests\Attendance\AttendanceOutageRecoveryApplyRequest;
use App\Http\Requests\Attendance\AttendanceOutageRecoveryPreviewRequest;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Http\Requests\Attendance\CheckOutRequest;
use App\Http\Requests\Attendance\CorrectionRequestReviewRequest;
use App\Http\Requests\Attendance\CorrectionRequestStoreRequest;
use App\Http\Requests\Attendance\MissingAttendanceRequestStoreRequest;
use App\Http\Requests\Attendance\MonthlySummaryRequest;
use App\Http\Requests\Attendance\ScanAttendanceRequest;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Services\Attendance\AttendanceCorrectionRequestStatus;
use App\Services\Attendance\AttendanceExportService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\AttendanceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendanceService,
        private AttendanceExportService $attendanceExportService,
    ) {}

    public function checkIn(CheckInRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->checkIn($request->user('api'), $request->validated()),
            Response::HTTP_CREATED,
        );
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->checkOut($request->user('api'), $request->validated())
        );
    }

    public function scan(ScanAttendanceRequest $request): JsonResponse
    {
        $result = $this->attendanceService->scan($request->user('api'), $request->validated());

        return response()->json(
            $result['body'],
            $result['status_code'],
        );
    }

    public function myToday(Request $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->myToday($request->user('api'))
        );
    }

    public function myHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            $this->attendanceService->myHistory($request->user('api'), $validated)
        );
    }

    public function mySummary(Request $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->mySummary($request->user('api'))
        );
    }

    public function submitCorrectionRequest(CorrectionRequestStoreRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->submitCorrectionRequest($request->user('api'), $request->validated()),
            Response::HTTP_CREATED,
        );
    }

    public function submitMissingAttendanceRequest(MissingAttendanceRequestStoreRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->submitMissingAttendanceRequest($request->user('api'), $request->validated()),
            Response::HTTP_CREATED,
        );
    }

    public function index(AttendanceIndexRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->index($request->user('api'), $request->validated())
        );
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        return response()->json(
            $this->attendanceService->show($request->user('api'), $attendance)
        );
    }

    public function correct(AttendanceCorrectRequest $request, Attendance $attendance): JsonResponse
    {
        return response()->json(
            $this->attendanceService->correct($request->user('api'), $attendance, $request->validated())
        );
    }

    public function storeManual(AttendanceManualStoreRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->storeManual($request->user('api'), $request->validated()),
            Response::HTTP_CREATED,
        );
    }

    public function todaySummary(Request $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->todaySummary($request->user('api'))
        );
    }

    public function monthlySummary(MonthlySummaryRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->monthlySummary($request->user('api'), $request->validated())
        );
    }

    public function outageRecoveryPreview(AttendanceOutageRecoveryPreviewRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->outageRecoveryPreview($request->user('api'), $request->validated())
        );
    }

    public function outageRecoveryApply(AttendanceOutageRecoveryApplyRequest $request): JsonResponse
    {
        return response()->json(
            $this->attendanceService->outageRecoveryApply($request->user('api'), $request->validated())
        );
    }

    public function correctionRequests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', AttendanceCorrectionRequestStatus::all())],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            $this->attendanceService->correctionRequests($request->user('api'), $validated)
        );
    }

    public function reviewCorrectionRequest(
        CorrectionRequestReviewRequest $request,
        AttendanceCorrectionRequest $attendanceCorrectionRequest,
    ): JsonResponse {
        return response()->json(
            $this->attendanceService->reviewCorrectionRequest(
                $request->user('api'),
                $attendanceCorrectionRequest,
                $request->validated(),
            )
        );
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', AttendanceStatus::all())],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            $this->attendanceService->auditLogs($request->user('api'), $validated)
        );
    }

    public function exportPdf(AttendanceExportRequest $request): BinaryFileResponse
    {
        $export = $this->attendanceExportService->exportPdf($request->user('api'), $request->validated());

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => $export['content_type']]
        )->deleteFileAfterSend(true);
    }

    public function exportExcel(AttendanceExportRequest $request): BinaryFileResponse
    {
        $export = $this->attendanceExportService->exportExcel($request->user('api'), $request->validated());

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => $export['content_type']]
        )->deleteFileAfterSend(true);
    }
}
