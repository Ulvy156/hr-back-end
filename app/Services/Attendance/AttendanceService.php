<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Employee;
use App\Models\User;
use App\PermissionName;
use App\Services\AuditLogService;
use App\Services\PublicHoliday\PublicHolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AttendanceService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PublicHolidayService $publicHolidayService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function checkIn(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return $this->performCheckIn($authenticatedUser, $employee, AttendanceSource::SelfService);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function checkOut(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return $this->performCheckOut($authenticatedUser, $employee, AttendanceSource::SelfService);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     body: array{message: string, data: array<string, mixed>},
     *     status_code: int
     * }
     */
    public function scan(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        $todayAttendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today()->toDateString())
            ->first();

        if ($todayAttendance === null || $todayAttendance->check_in === null) {
            return [
                'body' => $this->performCheckIn($authenticatedUser, $employee, AttendanceSource::Scan),
                'status_code' => 201,
            ];
        }

        if ($todayAttendance->check_out === null) {
            return [
                'body' => $this->performCheckOut($authenticatedUser, $employee, AttendanceSource::Scan),
                'status_code' => 200,
            ];
        }

        throw ValidationException::withMessages([
            'attendance' => ['You have already completed attendance for today.'],
        ]);
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function myToday(?User $authenticatedUser): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today()->toDateString())
            ->first();

        return [
            'data' => [
                'attendanceDate' => today()->toDateString(),
                'todayAttendanceStatus' => $this->deriveTodayAttendanceStatus($attendance),
                'nextAction' => $this->nextAction($attendance),
                'checkInTime' => $this->formatTime($attendance?->check_in),
                'checkOutTime' => $this->formatTime($attendance?->check_out),
                'workedMinutes' => $attendance?->worked_minutes ?? 0,
                'lateMinutes' => $attendance?->late_minutes ?? 0,
                'earlyLeaveMinutes' => $attendance?->early_leave_minutes ?? 0,
                'overtimeMinutes' => $attendance?->overtime_minutes ?? 0,
                'status' => $attendance?->status ?? AttendanceStatus::NotCheckedIn,
                'source' => $attendance?->source,
                'correctionStatus' => $attendance?->correction_status ?? 'none',
                'notes' => $attendance?->notes,
            ],
        ];
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    public function myHistory(?User $authenticatedUser, array $filters = []): LengthAwarePaginator
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? config('attendance.employee_history_per_page', 15)), 1), 100);

        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Attendance $attendance): array => $this->transformAttendance($attendance));
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function mySummary(?User $authenticatedUser): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $todayAttendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today()->toDateString())
            ->first();

        return [
            'data' => [
                'employee' => $this->transformEmployee($employee->loadMissing('department')),
                'today' => [
                    'attendanceDate' => today()->toDateString(),
                    'todayAttendanceStatus' => $this->deriveTodayAttendanceStatus($todayAttendance),
                    'nextAction' => $this->nextAction($todayAttendance),
                    'checkInTime' => $this->formatTime($todayAttendance?->check_in),
                    'checkOutTime' => $this->formatTime($todayAttendance?->check_out),
                    'workedMinutes' => $todayAttendance?->worked_minutes ?? 0,
                    'lateMinutes' => $todayAttendance?->late_minutes ?? 0,
                    'earlyLeaveMinutes' => $todayAttendance?->early_leave_minutes ?? 0,
                    'overtimeMinutes' => $todayAttendance?->overtime_minutes ?? 0,
                    'correctionStatus' => $todayAttendance?->correction_status ?? 'none',
                ],
                'thisWeek' => $this->employeeRangeSummary(
                    $employee,
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ),
                'thisMonth' => [
                    ...$this->employeeRangeSummary(
                        $employee,
                        now()->startOfMonth(),
                        now()->endOfMonth(),
                    ),
                    'pendingCorrectionRequests' => AttendanceCorrectionRequest::query()
                        ->where('employee_id', $employee->id)
                        ->where('status', AttendanceCorrectionRequestStatus::Pending)
                        ->count(),
                ],
            ],
        ];
    }

    /**
     * @param array{
     *     request_date: string,
     *     requested_check_in_time?: string|null,
     *     requested_check_out_time?: string|null,
     *     reason: string
     * } $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function submitCorrectionRequest(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $employee, $data): array {
            $requestDate = Carbon::createFromFormat('Y-m-d', $data['request_date'])->startOfDay();

            /** @var Attendance $attendance */
            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $requestDate->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $attendance instanceof Attendance) {
                throw ValidationException::withMessages([
                    'request_date' => ['No attendance record exists for this date. Use the missing attendance request endpoint instead.'],
                ]);
            }

            if ($attendance->correction_status === AttendanceCorrectionRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'request_date' => ['There is already a pending correction request for this attendance record.'],
                ]);
            }

            if ($attendance->correction_status === AttendanceCorrectionRequestStatus::Approved) {
                throw ValidationException::withMessages([
                    'request_date' => ['This attendance record has already been corrected and approved. A new correction request is only allowed after rejection.'],
                ]);
            }

            $requestedCheckIn = $this->parseClockTimeOnDate($requestDate, $data['requested_check_in_time'] ?? null)
                ?? $attendance->check_in;
            $requestedCheckOut = $this->parseClockTimeOnDate($requestDate, $data['requested_check_out_time'] ?? null)
                ?? $attendance->check_out;

            $this->assertAttendanceDateMatches($requestDate, $requestedCheckIn, $requestedCheckOut);
            $this->assertValidTimeOrder($requestedCheckIn, $requestedCheckOut);

            $correctionRequest = AttendanceCorrectionRequest::query()->create([
                'attendance_id' => $attendance->id,
                'employee_id' => $employee->id,
                'requested_check_in_time' => $requestedCheckIn,
                'requested_check_out_time' => $requestedCheckOut,
                'reason' => $data['reason'],
                'status' => AttendanceCorrectionRequestStatus::Pending,
            ]);

            $attendance->forceFill([
                'correction_status' => AttendanceCorrectionRequestStatus::Pending,
                'correction_reason' => $data['reason'],
            ])->save();

            $correctionRequest = $correctionRequest->fresh(['attendance', 'employee.department']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'correction_requested',
                description: 'attendance.correction_requested',
                causer: $authenticatedUser,
                subject: $correctionRequest,
                properties: [
                    'attendance_id' => $attendance->id,
                    'employee_id' => $employee->id,
                    'request_date' => $requestDate->toDateString(),
                    'status' => $correctionRequest?->status,
                ],
            );

            return [
                'message' => 'Attendance correction request submitted successfully.',
                'data' => $this->transformCorrectionRequest(
                    $correctionRequest
                ),
            ];
        });
    }

    /**
     * @param array{
     *     request_date: string,
     *     requested_check_in_time?: string|null,
     *     requested_check_out_time?: string|null,
     *     reason: string
     * } $data
     */
    public function submitMissingAttendanceRequest(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $employee, $data): array {
            $attendanceDate = Carbon::createFromFormat('Y-m-d', $data['request_date'])->startOfDay();

            $this->assertNoAttendanceExistsForDate($employee->id, $attendanceDate, 'request_date');
            $this->assertNoOpenMissingAttendanceRequest($employee->id, $attendanceDate, 'request_date');

            $requestedCheckIn = $this->parseClockTimeOnDate($attendanceDate, $data['requested_check_in_time'] ?? null);
            $requestedCheckOut = $this->parseClockTimeOnDate($attendanceDate, $data['requested_check_out_time'] ?? null);

            $this->assertAttendanceDateMatches($attendanceDate, $requestedCheckIn, $requestedCheckOut);
            $this->assertValidTimeOrder($requestedCheckIn, $requestedCheckOut);

            $correctionRequest = AttendanceCorrectionRequest::query()->create([
                'attendance_id' => null,
                'employee_id' => $employee->id,
                'requested_check_in_time' => $requestedCheckIn,
                'requested_check_out_time' => $requestedCheckOut,
                'reason' => $data['reason'],
                'status' => AttendanceCorrectionRequestStatus::Pending,
            ]);

            $correctionRequest = $correctionRequest->fresh(['attendance', 'employee.department']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'missing_attendance_requested',
                description: 'attendance.missing_attendance_requested',
                causer: $authenticatedUser,
                subject: $correctionRequest,
                properties: [
                    'employee_id' => $employee->id,
                    'request_date' => $attendanceDate->toDateString(),
                    'status' => $correctionRequest?->status,
                ],
            );

            return [
                'message' => 'Missing attendance request submitted successfully.',
                'data' => $this->transformCorrectionRequest($correctionRequest),
            ];
        });
    }

    /**
     * @param array{
     *     employee_id?: int,
     *     department_id?: int,
     *     status?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     per_page?: int
     * } $filters
     */
    public function index(?User $authenticatedUser, array $filters = []): LengthAwarePaginator
    {
        $authenticatedUser = $this->ensureManagementReader($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? config('attendance.management_history_per_page', 20)), 1), 100);
        $includeAudit = $authenticatedUser->can(PermissionName::AttendanceAuditView->value);

        return $this->filteredAttendanceQuery($filters, $includeAudit)
            ->paginate($perPage)
            ->through(fn (Attendance $attendance): array => $this->transformAttendance(
                $attendance,
                includeEmployee: true,
                includeAudit: $includeAudit,
            ));
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function show(?User $authenticatedUser, Attendance $attendance): array
    {
        $authenticatedUser = $this->ensureManagementReader($authenticatedUser);
        $includeAudit = $authenticatedUser->can(PermissionName::AttendanceAuditView->value);

        return [
            'data' => $this->transformAttendance(
                $attendance->loadMissing([
                    'employee.department',
                    'creator',
                    'updater',
                    'corrector',
                    'editor',
                ]),
                includeEmployee: true,
                includeAudit: $includeAudit,
            ),
        ];
    }

    /**
     * @param array{
     *     employee_id: int,
     *     attendance_date: string,
     *     check_in_time?: string|null,
     *     check_out_time?: string|null,
     *     status?: string|null,
     *     notes?: string|null,
     *     correction_reason?: string|null
     * } $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function storeManual(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureHrOperator($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $data): array {
            $attendanceDate = Carbon::parse($data['attendance_date'])->startOfDay();
            $checkIn = $this->parseDateTime($data['check_in_time'] ?? null);
            $checkOut = $this->parseDateTime($data['check_out_time'] ?? null);

            $this->assertAttendanceDateMatches($attendanceDate, $checkIn, $checkOut);

            $exists = Attendance::query()
                ->where('employee_id', $data['employee_id'])
                ->whereDate('attendance_date', $attendanceDate->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'attendance_date' => ['Attendance already exists for this employee on the selected date.'],
                ]);
            }

            $payload = $this->buildAttendancePayload(
                attendanceDate: $attendanceDate,
                checkIn: $checkIn,
                checkOut: $checkOut,
                requestedStatus: $data['status'] ?? null,
            );

            $attendance = Attendance::query()->create([
                'employee_id' => $data['employee_id'],
                'edited_by' => $authenticatedUser->id,
                'created_by' => $authenticatedUser->id,
                'updated_by' => $authenticatedUser->id,
                'attendance_date' => $attendanceDate->toDateString(),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                ...$payload,
                'source' => AttendanceSource::Manual,
                'notes' => $data['notes'] ?? null,
                'correction_reason' => $data['correction_reason'] ?? null,
            ]);

            $attendance = $attendance->fresh(['employee.department', 'creator', 'updater']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'manual_created',
                description: 'attendance.manual_created',
                causer: $authenticatedUser,
                subject: $attendance,
                properties: [
                    'employee_id' => $attendance?->employee_id,
                    'attendance_date' => $attendance?->attendance_date?->toDateString(),
                    'status' => $attendance?->status,
                    'source' => $attendance?->source,
                ],
            );

            return [
                'message' => 'Attendance created successfully.',
                'data' => $this->transformAttendance(
                    $attendance,
                    includeEmployee: true,
                    includeAudit: false,
                ),
            ];
        });
    }

    /**
     * @param array{
     *     check_in_time?: string|null,
     *     check_out_time?: string|null,
     *     status?: string|null,
     *     notes?: string|null,
     *     correction_reason?: string|null
     * } $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function correct(?User $authenticatedUser, Attendance $attendance, array $data): array
    {
        $authenticatedUser = $this->ensureHrOperator($authenticatedUser);

        if ($data === []) {
            throw ValidationException::withMessages([
                'attendance' => ['At least one field is required to correct attendance.'],
            ]);
        }

        return DB::transaction(function () use ($authenticatedUser, $attendance, $data): array {
            /** @var Attendance $lockedAttendance */
            $lockedAttendance = Attendance::query()
                ->lockForUpdate()
                ->findOrFail($attendance->id);

            $checkIn = array_key_exists('check_in_time', $data)
                ? $this->parseDateTime($data['check_in_time'])
                : $this->parseDateTime($lockedAttendance->check_in?->toIso8601String());

            $checkOut = array_key_exists('check_out_time', $data)
                ? $this->parseDateTime($data['check_out_time'])
                : $this->parseDateTime($lockedAttendance->check_out?->toIso8601String());

            $attendanceDate = Carbon::parse($lockedAttendance->attendance_date);

            $this->assertAttendanceDateMatches($attendanceDate, $checkIn, $checkOut);

            $payload = $this->buildAttendancePayload(
                attendanceDate: $attendanceDate,
                checkIn: $checkIn,
                checkOut: $checkOut,
                requestedStatus: $data['status'] ?? null,
                markAsCorrected: true,
            );

            $lockedAttendance->forceFill([
                'edited_by' => $authenticatedUser->id,
                'updated_by' => $authenticatedUser->id,
                'corrected_by' => $authenticatedUser->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                ...$payload,
                'source' => AttendanceSource::Correction,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $lockedAttendance->notes,
                'correction_reason' => array_key_exists('correction_reason', $data) ? $data['correction_reason'] : $lockedAttendance->correction_reason,
                'correction_status' => AttendanceCorrectionRequestStatus::Approved,
            ])->save();

            $lockedAttendance = $lockedAttendance->fresh(['employee.department', 'corrector', 'updater', 'editor']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'corrected',
                description: 'attendance.corrected',
                causer: $authenticatedUser,
                subject: $lockedAttendance,
                properties: [
                    'employee_id' => $lockedAttendance?->employee_id,
                    'attendance_date' => $lockedAttendance?->attendance_date?->toDateString(),
                    'status' => $lockedAttendance?->status,
                    'correction_status' => $lockedAttendance?->correction_status,
                ],
            );

            return [
                'message' => 'Attendance corrected successfully.',
                'data' => $this->transformAttendance(
                    $lockedAttendance,
                    includeEmployee: true,
                    includeAudit: false,
                ),
            ];
        });
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function todaySummary(?User $authenticatedUser): array
    {
        $this->ensureManagementReader($authenticatedUser);

        $todayQuery = Attendance::query()->whereDate('attendance_date', today()->toDateString());
        $activeEmployees = $this->activeEmployeesQuery()->count();
        $checkedInCount = (clone $todayQuery)->whereNotNull('check_in')->distinct()->count('employee_id');
        $checkedOutCount = (clone $todayQuery)->whereNotNull('check_in')->whereNotNull('check_out')->distinct()->count('employee_id');
        $lateCount = (clone $todayQuery)->where('late_minutes', '>', 0)->distinct()->count('employee_id');
        $earlyLeaveCount = (clone $todayQuery)->where('early_leave_minutes', '>', 0)->distinct()->count('employee_id');
        $missingCheckOutCount = (clone $todayQuery)->whereNotNull('check_in')->whereNull('check_out')->distinct()->count('employee_id');

        return [
            'data' => [
                'date' => today()->toDateString(),
                'totals' => [
                    'totalEmployees' => Employee::query()->count(),
                    'activeEmployees' => $activeEmployees,
                    'checkedInTodayCount' => $checkedInCount,
                    'checkedOutTodayCount' => $checkedOutCount,
                    'missingAttendanceCount' => max($activeEmployees - $checkedOutCount, 0),
                    'lateCountToday' => $lateCount,
                    'earlyLeaveCountToday' => $earlyLeaveCount,
                    'employeesOnLeaveTodayCount' => $this->employeesOnLeaveTodayCount(),
                    'pendingCorrectionRequestsCount' => AttendanceCorrectionRequest::query()
                        ->where('status', AttendanceCorrectionRequestStatus::Pending)
                        ->count(),
                ],
                'issues' => [
                    'missingCheckoutCount' => $missingCheckOutCount,
                    'incompleteAttendanceCount' => max($activeEmployees - $checkedOutCount, 0),
                ],
            ],
        ];
    }

    /**
     * @param  array{month: string, department_id?: int}  $filters
     * @return array{data: array<string, mixed>}
     */
    public function monthlySummary(?User $authenticatedUser, array $filters): array
    {
        $this->ensureManagementReader($authenticatedUser);

        $month = Carbon::createFromFormat('Y-m', $filters['month'])->startOfMonth();
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        $attendanceQuery = Attendance::query()
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when(
                isset($filters['department_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'employee',
                    fn (Builder $employeeQuery): Builder => $employeeQuery->where('department_id', $filters['department_id'])
                )
            );

        $recordsCount = (clone $attendanceQuery)->count();
        $completedCount = (clone $attendanceQuery)->whereNotNull('check_in')->whereNotNull('check_out')->count();
        $lateCount = (clone $attendanceQuery)->where('late_minutes', '>', 0)->count();
        $correctedCount = (clone $attendanceQuery)->where('status', AttendanceStatus::Corrected)->count();
        $absentCount = (clone $attendanceQuery)->where('status', AttendanceStatus::Absent)->count();
        $totalWorkedMinutes = (int) ((clone $attendanceQuery)->sum('worked_minutes'));

        $departmentBreakdown = Attendance::query()
            ->selectRaw('departments.id as department_id, departments.name as department_name')
            ->selectRaw('COUNT(attendances.id) as total_records')
            ->selectRaw('SUM(CASE WHEN attendances.check_in IS NOT NULL AND attendances.check_out IS NOT NULL THEN 1 ELSE 0 END) as completed_records')
            ->selectRaw('SUM(CASE WHEN attendances.late_minutes > 0 THEN 1 ELSE 0 END) as late_records')
            ->selectRaw('SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as corrected_records', [AttendanceStatus::Corrected])
            ->selectRaw('SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as absent_records', [AttendanceStatus::Absent])
            ->selectRaw('SUM(attendances.worked_minutes) as total_worked_minutes')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('attendances.attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when(
                isset($filters['department_id']),
                fn (Builder $query): Builder => $query->where('employees.department_id', $filters['department_id'])
            )
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get()
            ->map(fn (object $row): array => [
                'departmentId' => $row->department_id,
                'departmentName' => $row->department_name,
                'totalRecords' => (int) $row->total_records,
                'completedRecords' => (int) $row->completed_records,
                'lateRecords' => (int) $row->late_records,
                'correctedRecords' => (int) $row->corrected_records,
                'absentRecords' => (int) $row->absent_records,
                'totalWorkedMinutes' => (int) $row->total_worked_minutes,
            ])
            ->all();

        return [
            'data' => [
                'month' => $month->format('Y-m'),
                'dateRange' => [
                    'from' => $startDate->toDateString(),
                    'to' => $endDate->toDateString(),
                ],
                'totals' => [
                    'totalRecords' => $recordsCount,
                    'completedRecords' => $completedCount,
                    'lateRecords' => $lateCount,
                    'correctedRecords' => $correctedCount,
                    'absentRecords' => $absentCount,
                    'totalWorkedMinutes' => $totalWorkedMinutes,
                    'averageWorkedMinutes' => $completedCount > 0
                        ? (int) round($totalWorkedMinutes / $completedCount)
                        : 0,
                    'pendingCorrectionRequestsCount' => AttendanceCorrectionRequest::query()
                        ->where('status', AttendanceCorrectionRequestStatus::Pending)
                        ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->copy()->endOfDay()])
                        ->count(),
                ],
                'byDepartment' => $departmentBreakdown,
            ],
        ];
    }

    /**
     * @param  array{
     *     date?: string,
     *     search?: string,
     *     department_id?: int,
     *     per_page?: int
     * }  $filters
     * @return array{data: array<string, mixed>}
     */
    public function outageRecoveryPreview(?User $authenticatedUser, array $filters = []): array
    {
        $this->ensureHrOperator($authenticatedUser);

        $date = Carbon::parse($filters['date'] ?? today()->toDateString())->startOfDay();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $selectedEmployees = $this->applyOutageRecoveryEmployeeFilters(
            $this->outageRecoveryCandidateQuery($date),
            $filters,
        )
            ->with(['department', 'currentPosition'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($perPage)
            ->through(fn (Employee $employee): array => $this->transformOutageRecoveryEmployee($employee, true));

        $employeesOnLeave = $this->applyOutageRecoveryEmployeeFilters(
            $this->outageRecoveryEmployeesOnLeaveQuery($date),
            $filters,
        )
            ->with(['department', 'currentPosition'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (Employee $employee): array => $this->transformOutageRecoveryEmployee($employee, false, 'on_leave'))
            ->values()
            ->all();

        $employeesWithAttendance = $this->applyOutageRecoveryEmployeeFilters(
            $this->outageRecoveryEmployeesWithAttendanceQuery($date),
            $filters,
        )
            ->with(['department', 'currentPosition', 'attendances' => fn ($query) => $query->whereDate('attendance_date', $date->toDateString())])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (Employee $employee): array {
                $attendance = $employee->attendances->first();

                return [
                    ...$this->transformOutageRecoveryEmployee($employee, false, 'existing_attendance'),
                    'existingAttendance' => $attendance instanceof Attendance
                        ? [
                            'id' => $attendance->id,
                            'status' => $attendance->status,
                            'source' => $attendance->source,
                        ]
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'data' => [
                'date' => $date->toDateString(),
                'defaults' => [
                    'checkInAt' => $this->workStart($date)->toIso8601String(),
                    'checkOutAt' => $this->workEnd($date)->toIso8601String(),
                    'notes' => $this->outageRecoveryDefaultNotes(),
                ],
                'selectedEmployees' => $selectedEmployees,
                'skipped' => [
                    'counts' => [
                        'onLeave' => count($employeesOnLeave),
                        'existingAttendance' => count($employeesWithAttendance),
                    ],
                    'onLeave' => $employeesOnLeave,
                    'existingAttendance' => $employeesWithAttendance,
                ],
            ],
        ];
    }

    /**
     * @param array{
     *     date: string,
     *     employee_ids: array<int, int>,
     *     check_in_time?: string|null,
     *     check_out_time?: string|null,
     *     notes?: string|null
     * } $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function outageRecoveryApply(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureHrOperator($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $data): array {
            $date = Carbon::parse($data['date'])->startOfDay();
            $employeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'])));
            $hasExplicitCheckInTime = array_key_exists('check_in_time', $data);
            $hasExplicitCheckOutTime = array_key_exists('check_out_time', $data);
            $checkIn = $hasExplicitCheckInTime
                ? $this->parseDateTime($data['check_in_time'])
                : $this->workStart($date);
            $checkOut = $hasExplicitCheckOutTime
                ? $this->parseDateTime($data['check_out_time'])
                : ($hasExplicitCheckInTime ? null : $this->workEnd($date));
            $notes = $data['notes'] ?? $this->outageRecoveryDefaultNotes();

            $this->assertAttendanceDateMatches($date, $checkIn, $checkOut);
            $this->assertValidTimeOrder($checkIn, $checkOut);
            $this->assertNotFutureAttendanceTimes($checkIn, $checkOut);

            $eligibleEmployees = $this->outageRecoveryCandidateQuery($date)
                ->whereIn('id', $employeeIds)
                ->lockForUpdate()
                ->get();

            if ($eligibleEmployees->count() !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'employee_ids' => ['Some selected employees are no longer eligible for outage recovery attendance. Refresh the preview and try again.'],
                ]);
            }

            $payload = $this->buildAttendancePayload(
                attendanceDate: $date,
                checkIn: $checkIn,
                checkOut: $checkOut,
            );

            $createdAttendances = [];

            foreach ($eligibleEmployees as $employee) {
                $existingAttendance = Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $date->toDateString())
                    ->lockForUpdate()
                    ->exists();

                if ($existingAttendance) {
                    throw ValidationException::withMessages([
                        'employee_ids' => ['Some selected employees already have attendance for this date. Refresh the preview and try again.'],
                    ]);
                }

                $createdAttendances[] = Attendance::query()->create([
                    'employee_id' => $employee->id,
                    'edited_by' => $authenticatedUser->id,
                    'created_by' => $authenticatedUser->id,
                    'updated_by' => $authenticatedUser->id,
                    'attendance_date' => $date->toDateString(),
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    ...$payload,
                    'source' => AttendanceSource::Manual,
                    'notes' => $notes,
                ]);
            }

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'outage_recovery_applied',
                description: 'attendance.outage_recovery_applied',
                causer: $authenticatedUser,
                properties: [
                    'date' => $date->toDateString(),
                    'employee_ids' => $employeeIds,
                    'created_count' => count($createdAttendances),
                    'notes' => $notes,
                ],
            );

            return [
                'message' => 'Outage recovery attendance created successfully.',
                'data' => [
                    'date' => $date->toDateString(),
                    'createdCount' => count($createdAttendances),
                    'notes' => $notes,
                    'employees' => $eligibleEmployees
                        ->loadMissing(['department', 'currentPosition'])
                        ->map(fn (Employee $employee): array => $this->transformOutageRecoveryEmployee($employee, true))
                        ->values()
                        ->all(),
                ],
            ];
        });
    }

    /**
     * @param array{
     *     employee_id?: int,
     *     status?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     per_page?: int
     * } $filters
     */
    public function correctionRequests(?User $authenticatedUser, array $filters = []): LengthAwarePaginator
    {
        $authenticatedUser = $this->ensureManagementReader($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? config('attendance.correction_requests_per_page', 20)), 1), 100);

        return AttendanceCorrectionRequest::query()
            ->with([
                'attendance.employee.department',
                'employee.department',
                'reviewer',
            ])
            ->when(
                isset($filters['employee_id']),
                fn (Builder $query): Builder => $query->where('employee_id', $filters['employee_id'])
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['from_date']),
                fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $filters['from_date'])
            )
            ->when(
                isset($filters['to_date']),
                fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $filters['to_date'])
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (AttendanceCorrectionRequest $correctionRequest): array => $this->transformCorrectionRequest($correctionRequest));
    }

    /**
     * @param  array{status: string, review_note?: string|null}  $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function reviewCorrectionRequest(
        ?User $authenticatedUser,
        AttendanceCorrectionRequest $correctionRequest,
        array $data,
    ): array {
        $authenticatedUser = $this->ensureHrOperator($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $correctionRequest, $data): array {
            /** @var AttendanceCorrectionRequest $lockedCorrectionRequest */
            $lockedCorrectionRequest = AttendanceCorrectionRequest::query()
                ->with('attendance')
                ->lockForUpdate()
                ->findOrFail($correctionRequest->id);

            if ($lockedCorrectionRequest->status !== AttendanceCorrectionRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['This correction request has already been reviewed.'],
                ]);
            }

            $lockedCorrectionRequest->forceFill([
                'status' => $data['status'],
                'reviewed_by' => $authenticatedUser->id,
                'reviewed_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ])->save();

            if ($lockedCorrectionRequest->attendance !== null) {
                $attendance = $lockedCorrectionRequest->attendance->fresh();

                if ($attendance instanceof Attendance) {
                    $attendance->forceFill([
                        'correction_status' => $data['status'],
                        'correction_reason' => $lockedCorrectionRequest->reason,
                    ]);

                    if ($data['status'] === AttendanceCorrectionRequestStatus::Approved) {
                        $payload = $this->buildAttendancePayload(
                            attendanceDate: Carbon::parse($attendance->attendance_date),
                            checkIn: $lockedCorrectionRequest->requested_check_in_time,
                            checkOut: $lockedCorrectionRequest->requested_check_out_time,
                            markAsCorrected: true,
                        );

                        $attendance->forceFill([
                            'edited_by' => $authenticatedUser->id,
                            'updated_by' => $authenticatedUser->id,
                            'corrected_by' => $authenticatedUser->id,
                            'check_in' => $lockedCorrectionRequest->requested_check_in_time,
                            'check_out' => $lockedCorrectionRequest->requested_check_out_time,
                            ...$payload,
                            'source' => AttendanceSource::Correction,
                        ]);
                    }

                    $attendance->save();
                }
            } elseif ($data['status'] === AttendanceCorrectionRequestStatus::Approved) {
                $attendanceDate = $this->correctionRequestAttendanceDate($lockedCorrectionRequest);

                $this->assertNoAttendanceExistsForDate($lockedCorrectionRequest->employee_id, $attendanceDate);

                $payload = $this->buildAttendancePayload(
                    attendanceDate: $attendanceDate,
                    checkIn: $lockedCorrectionRequest->requested_check_in_time,
                    checkOut: $lockedCorrectionRequest->requested_check_out_time,
                    markAsCorrected: true,
                );

                $createdAttendance = Attendance::query()->create([
                    'employee_id' => $lockedCorrectionRequest->employee_id,
                    'edited_by' => $authenticatedUser->id,
                    'created_by' => $authenticatedUser->id,
                    'updated_by' => $authenticatedUser->id,
                    'corrected_by' => $authenticatedUser->id,
                    'attendance_date' => $attendanceDate->toDateString(),
                    'check_in' => $lockedCorrectionRequest->requested_check_in_time,
                    'check_out' => $lockedCorrectionRequest->requested_check_out_time,
                    'source' => AttendanceSource::Correction,
                    'correction_reason' => $lockedCorrectionRequest->reason,
                    ...$payload,
                ]);

                $lockedCorrectionRequest->forceFill([
                    'attendance_id' => $createdAttendance->id,
                ])->save();
            }

            $lockedCorrectionRequest = $lockedCorrectionRequest->fresh(['attendance.employee.department', 'employee.department', 'reviewer']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'correction_reviewed',
                description: 'attendance.correction_reviewed',
                causer: $authenticatedUser,
                subject: $lockedCorrectionRequest,
                properties: [
                    'attendance_id' => $lockedCorrectionRequest?->attendance_id,
                    'employee_id' => $lockedCorrectionRequest?->employee_id,
                    'status' => $lockedCorrectionRequest?->status,
                    'reviewed_at' => $lockedCorrectionRequest?->reviewed_at?->toIso8601String(),
                ],
            );

            return [
                'message' => 'Attendance correction request reviewed successfully.',
                'data' => $this->transformCorrectionRequest(
                    $lockedCorrectionRequest
                ),
            ];
        });
    }

    /**
     * @param array{
     *     employee_id?: int,
     *     status?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     actor_id?: int,
     *     per_page?: int
     * } $filters
     */
    public function auditLogs(?User $authenticatedUser, array $filters = []): LengthAwarePaginator
    {
        $this->ensureAdminReader($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? config('attendance.audit_logs_per_page', 20)), 1), 100);

        return $this->filteredAttendanceQuery($filters, true)
            ->when(
                isset($filters['actor_id']),
                fn (Builder $query): Builder => $query->where(function (Builder $auditQuery) use ($filters): void {
                    $auditQuery->where('created_by', $filters['actor_id'])
                        ->orWhere('updated_by', $filters['actor_id'])
                        ->orWhere('corrected_by', $filters['actor_id'])
                        ->orWhere('edited_by', $filters['actor_id']);
                })
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Attendance $attendance): array => $this->transformAttendance(
                $attendance,
                includeEmployee: true,
                includeAudit: true,
            ));
    }

    private function ensureAuthenticated(?User $authenticatedUser): User
    {
        if ($authenticatedUser === null) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated.');
        }

        return $authenticatedUser;
    }

    private function ensureEmployeeProfile(User $authenticatedUser): Employee
    {
        $employee = $authenticatedUser->loadMissing('employee.department')->employee;

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages([
                'user' => ['The authenticated user is not linked to an employee profile.'],
            ]);
        }

        return $employee;
    }

    private function ensureManagementReader(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->can(PermissionName::AttendanceViewAny->value)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function ensureHrOperator(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->can(PermissionName::AttendanceManage->value)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function ensureAdminReader(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->can(PermissionName::AttendanceAuditView->value)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    /**
     * @param array{
     *     employee_id?: int,
     *     department_id?: int,
     *     status?: string,
     *     from_date?: string,
     *     to_date?: string
     * } $filters
     */
    public function filteredAttendanceQuery(array $filters, bool $includeAudit = false): Builder
    {
        return Attendance::query()
            ->with(array_filter([
                'employee.department',
                $includeAudit ? 'creator' : null,
                $includeAudit ? 'updater' : null,
                $includeAudit ? 'corrector' : null,
                $includeAudit ? 'editor' : null,
            ]))
            ->when(
                isset($filters['employee_id']),
                fn (Builder $query): Builder => $query->where('employee_id', $filters['employee_id'])
            )
            ->when(
                isset($filters['department_id']),
                fn (Builder $query): Builder => $query->whereHas(
                    'employee',
                    fn (Builder $employeeQuery): Builder => $employeeQuery->where('department_id', $filters['department_id'])
                )
            )
            ->when(
                isset($filters['month']),
                function (Builder $query) use ($filters): Builder {
                    $month = Carbon::createFromFormat('Y-m', $filters['month']);

                    return $query->whereBetween('attendance_date', [
                        $month->copy()->startOfMonth()->toDateString(),
                        $month->copy()->endOfMonth()->toDateString(),
                    ]);
                }
            )
            ->when(
                isset($filters['from_date']),
                fn (Builder $query): Builder => $query->whereDate('attendance_date', '>=', $filters['from_date'])
            )
            ->when(
                isset($filters['to_date']),
                fn (Builder $query): Builder => $query->whereDate('attendance_date', '<=', $filters['to_date'])
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $this->applyAttendanceStatusFilter($query, $filters['status'])
            )
            ->when(
                isset($filters['employee_search']),
                fn (Builder $query): Builder => $query->whereHas('employee', function (Builder $employeeQuery) use ($filters): Builder {
                    $search = trim((string) $filters['employee_search']);

                    return $employeeQuery->where(function (Builder $nameQuery) use ($search): void {
                        $nameQuery->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    });
                })
            )
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in')
            ->orderByDesc('id');
    }

    private function applyAttendanceStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            AttendanceStatus::CheckedIn => $query->whereNotNull('check_in')->whereNull('check_out'),
            AttendanceStatus::CheckedOut => $query->whereNotNull('check_in')->whereNotNull('check_out'),
            AttendanceStatus::NotCheckedIn => $query->where('status', AttendanceStatus::Absent),
            default => $query->where('status', $status),
        };
    }

    /**
     * @return array{message: string, data: array<string, mixed>}
     */
    private function performCheckIn(User $authenticatedUser, Employee $employee, string $source): array
    {
        return DB::transaction(function () use ($authenticatedUser, $employee, $source): array {
            $timestamp = now();

            $this->assertEmployeeNotOnApprovedLeave($employee, $timestamp);

            /** @var Attendance|null $attendance */
            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $timestamp->toDateString())
                ->lockForUpdate()
                ->first();

            if ($attendance !== null && $attendance->check_in !== null) {
                throw ValidationException::withMessages([
                    'attendance' => ['You have already checked in for today.'],
                ]);
            }

            $payload = $this->buildAttendancePayload(
                attendanceDate: $timestamp,
                checkIn: $timestamp,
                checkOut: null,
            );

            if ($attendance === null) {
                $attendance = Attendance::query()->create([
                    'employee_id' => $employee->id,
                    'edited_by' => $authenticatedUser->id,
                    'created_by' => $authenticatedUser->id,
                    'updated_by' => $authenticatedUser->id,
                    'attendance_date' => $timestamp->toDateString(),
                    'check_in' => $timestamp,
                    'check_out' => null,
                    ...$payload,
                    'source' => $source,
                ]);
            } else {
                $attendance->forceFill([
                    'edited_by' => $authenticatedUser->id,
                    'updated_by' => $authenticatedUser->id,
                    'check_in' => $timestamp,
                    'check_out' => null,
                    ...$payload,
                    'source' => $source,
                ])->save();
            }

            $attendance = $attendance->fresh(['employee.department']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'check_in',
                description: 'attendance.check_in',
                causer: $authenticatedUser,
                subject: $attendance,
                properties: [
                    'employee_id' => $employee->id,
                    'attendance_date' => $timestamp->toDateString(),
                    'status' => $attendance?->status,
                    'source' => $attendance?->source,
                ],
            );

            return [
                'message' => 'Check-in recorded successfully.',
                'data' => $this->transformAttendance(
                    $attendance,
                    includeEmployee: false,
                    includeAudit: false,
                ),
            ];
        });
    }

    /**
     * @return array{message: string, data: array<string, mixed>}
     */
    private function performCheckOut(User $authenticatedUser, Employee $employee, string $source): array
    {
        return DB::transaction(function () use ($authenticatedUser, $employee, $source): array {
            $timestamp = now();

            $this->assertEmployeeNotOnApprovedLeave($employee, $timestamp);

            /** @var Attendance|null $attendance */
            $attendance = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $timestamp->toDateString())
                ->lockForUpdate()
                ->first();

            if ($attendance === null || $attendance->check_in === null) {
                throw ValidationException::withMessages([
                    'attendance' => ['You must check in before checking out.'],
                ]);
            }

            if ($attendance->check_out !== null) {
                throw ValidationException::withMessages([
                    'attendance' => ['You have already checked out for today.'],
                ]);
            }

            $payload = $this->buildAttendancePayload(
                attendanceDate: Carbon::parse($attendance->attendance_date),
                checkIn: Carbon::parse($attendance->check_in),
                checkOut: $timestamp,
            );

            $attendance->forceFill([
                'edited_by' => $authenticatedUser->id,
                'updated_by' => $authenticatedUser->id,
                'check_out' => $timestamp,
                ...$payload,
                'source' => $source,
            ])->save();

            $attendance = $attendance->fresh(['employee.department']);

            $this->auditLogService->log(
                logName: 'attendance',
                event: 'check_out',
                description: 'attendance.check_out',
                causer: $authenticatedUser,
                subject: $attendance,
                properties: [
                    'employee_id' => $employee->id,
                    'attendance_date' => $timestamp->toDateString(),
                    'status' => $attendance?->status,
                    'worked_minutes' => $attendance?->worked_minutes,
                    'source' => $attendance?->source,
                ],
            );

            return [
                'message' => 'Check-out recorded successfully.',
                'data' => $this->transformAttendance(
                    $attendance,
                    includeEmployee: false,
                    includeAudit: false,
                ),
            ];
        });
    }

    /**
     * @return array{
     *     worked_minutes: int,
     *     late_minutes: int,
     *     early_leave_minutes: int,
     *     overtime_minutes: int,
     *     status: string,
     *     correction_status: string
     * }
     */
    private function buildAttendancePayload(
        CarbonInterface $attendanceDate,
        ?CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
        ?string $requestedStatus = null,
        bool $markAsCorrected = false,
    ): array {
        $this->assertValidTimeOrder($checkIn, $checkOut, $requestedStatus);

        if ($requestedStatus === AttendanceStatus::Absent) {
            if ($checkIn !== null || $checkOut !== null) {
                throw ValidationException::withMessages([
                    'status' => ['Absent attendance cannot include check-in or check-out times.'],
                ]);
            }

            return [
                'worked_minutes' => 0,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'status' => AttendanceStatus::Absent,
                'correction_status' => $markAsCorrected
                    ? AttendanceCorrectionRequestStatus::Approved
                    : 'none',
            ];
        }

        if ($checkIn === null && $checkOut === null) {
            throw ValidationException::withMessages([
                'attendance' => ['Attendance must include a check-in time or be marked as absent.'],
            ]);
        }

        if ($checkIn !== null && $checkOut === null) {
            return [
                'worked_minutes' => 0,
                'late_minutes' => $this->lateMinutes($attendanceDate, $checkIn),
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'status' => $markAsCorrected ? AttendanceStatus::Corrected : AttendanceStatus::CheckedIn,
                'correction_status' => $markAsCorrected
                    ? AttendanceCorrectionRequestStatus::Approved
                    : 'none',
            ];
        }

        $workedMinutes = $this->workedMinutes($attendanceDate, $checkIn, $checkOut);
        $overtimeMinutes = $this->overtimeMinutes($attendanceDate, $checkOut, $workedMinutes, $checkIn);
        $lateMinutes = $this->netLateMinutes($attendanceDate, $checkIn, $checkOut, $overtimeMinutes, $workedMinutes);
        $earlyLeaveMinutes = $this->earlyLeaveMinutes($attendanceDate, $checkOut);

        return [
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'status' => $markAsCorrected
                ? AttendanceStatus::Corrected
                : ($lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::Present),
            'correction_status' => $markAsCorrected
                ? AttendanceCorrectionRequestStatus::Approved
                : 'none',
        ];
    }

    private function assertValidTimeOrder(
        ?CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
        ?string $requestedStatus = null,
    ): void {
        if ($checkOut !== null && $checkIn === null) {
            throw ValidationException::withMessages([
                'check_in_time' => ['Check-in time is required when check-out time is present.'],
            ]);
        }

        if ($checkIn !== null && $checkOut !== null && $checkOut->lt($checkIn)) {
            throw ValidationException::withMessages([
                'check_out_time' => ['Check-out time must be after or equal to check-in time.'],
            ]);
        }

        if ($requestedStatus !== AttendanceStatus::Absent && $checkIn === null && $checkOut === null) {
            throw ValidationException::withMessages([
                'check_in_time' => ['Check-in time is required unless the attendance is marked absent.'],
            ]);
        }
    }

    private function assertAttendanceDateMatches(
        CarbonInterface|string $attendanceDate,
        ?CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
    ): void {
        $attendanceDateString = $attendanceDate instanceof CarbonInterface
            ? $attendanceDate->toDateString()
            : Carbon::parse($attendanceDate)->toDateString();

        foreach ([$checkIn, $checkOut] as $timestamp) {
            if ($timestamp !== null && $timestamp->toDateString() !== $attendanceDateString) {
                throw ValidationException::withMessages([
                    'attendance_date' => ['Attendance times must match the attendance date.'],
                ]);
            }
        }
    }

    private function assertNotFutureAttendanceTimes(
        ?CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
    ): void {
        $currentTime = now();

        if ($checkIn !== null && $checkIn->gt($currentTime)) {
            throw ValidationException::withMessages([
                'check_in_time' => ['Check-in time cannot be in the future.'],
            ]);
        }

        if ($checkOut !== null && $checkOut->gt($currentTime)) {
            throw ValidationException::withMessages([
                'check_out_time' => ['Check-out time cannot be in the future.'],
            ]);
        }
    }

    private function assertNoAttendanceExistsForDate(
        int $employeeId,
        CarbonInterface $attendanceDate,
        string $field = 'attendance_date',
    ): void {
        $alreadyExists = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $attendanceDate->toDateString())
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                $field => ['An attendance record already exists for this date. Please use the correction request endpoint instead.'],
            ]);
        }
    }

    private function assertNoOpenMissingAttendanceRequest(
        int $employeeId,
        CarbonInterface $attendanceDate,
        string $field = 'attendance_date',
    ): void {
        $existingRequest = AttendanceCorrectionRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [
                AttendanceCorrectionRequestStatus::Pending,
                AttendanceCorrectionRequestStatus::Approved,
            ])
            ->whereNull('attendance_id')
            ->where(function (Builder $query) use ($attendanceDate): void {
                $date = $attendanceDate->toDateString();

                $query->whereDate('requested_check_in_time', $date)
                    ->orWhereDate('requested_check_out_time', $date);
            })
            ->exists();

        if ($existingRequest) {
            throw ValidationException::withMessages([
                $field => ['A missing attendance request for this date already exists and can only be submitted again after rejection.'],
            ]);
        }
    }

    private function correctionRequestAttendanceDate(AttendanceCorrectionRequest $correctionRequest): Carbon
    {
        $attendanceDate = $correctionRequest->requested_check_in_time
            ?? $correctionRequest->requested_check_out_time;

        if (! $attendanceDate instanceof CarbonInterface) {
            throw ValidationException::withMessages([
                'attendance_date' => ['Unable to determine the attendance date for this request.'],
            ]);
        }

        return Carbon::parse($attendanceDate)->startOfDay();
    }

    private function lateMinutes(CarbonInterface $attendanceDate, CarbonInterface $checkIn): int
    {
        $start = $this->workStart($attendanceDate);

        return $checkIn->gt($start) ? $this->wholeMinutesBetween($start, $checkIn) : 0;
    }

    private function netLateMinutes(
        CarbonInterface $attendanceDate,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        ?int $overtimeMinutes = null,
        ?int $workedMinutes = null,
    ): int {
        $lateMinutes = $this->lateMinutes($attendanceDate, $checkIn);
        $overtimeCreditMinutes = $overtimeMinutes ?? $this->overtimeMinutes(
            $attendanceDate,
            $checkOut,
            $workedMinutes,
            $checkIn,
        );

        return max($lateMinutes - $overtimeCreditMinutes, 0);
    }

    private function earlyLeaveMinutes(CarbonInterface $attendanceDate, CarbonInterface $checkOut): int
    {
        $end = $this->workEnd($attendanceDate);

        return $checkOut->lt($end) ? $this->wholeMinutesBetween($checkOut, $end) : 0;
    }

    private function overtimeMinutes(
        CarbonInterface $attendanceDate,
        CarbonInterface $checkOut,
        ?int $workedMinutes = null,
        ?CarbonInterface $checkIn = null,
    ): int {
        if ($this->isPublicHoliday($attendanceDate) || $attendanceDate->isWeekend()) {
            if ($workedMinutes !== null) {
                return max($workedMinutes, 0);
            }

            if ($checkIn === null) {
                return 0;
            }

            return $this->workedMinutes($attendanceDate, $checkIn, $checkOut);
        }

        $end = $this->workEnd($attendanceDate);

        return $checkOut->gt($end) ? $this->wholeMinutesBetween($end, $checkOut) : 0;
    }

    private function isPublicHoliday(CarbonInterface $attendanceDate): bool
    {
        return in_array(
            $attendanceDate->toDateString(),
            $this->publicHolidayService->holidayDatesBetween($attendanceDate, $attendanceDate),
            true,
        );
    }

    private function workStart(CarbonInterface $attendanceDate): Carbon
    {
        return Carbon::parse($attendanceDate->toDateString().' '.config('attendance.work_start_time', '08:00:00'));
    }

    private function workEnd(CarbonInterface $attendanceDate): Carbon
    {
        return Carbon::parse($attendanceDate->toDateString().' '.config('attendance.work_end_time', '17:00:00'));
    }

    private function breakMinutes(
        CarbonInterface $attendanceDate,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
    ): int {
        $breakStart = Carbon::parse($attendanceDate->toDateString().' '.config('attendance.break_start_time', '12:00:00'));
        $breakEnd = Carbon::parse($attendanceDate->toDateString().' '.config('attendance.break_end_time', '13:00:00'));

        if ($breakEnd->lte($breakStart)) {
            return 0;
        }

        $overlapStart = $checkIn->greaterThan($breakStart) ? $checkIn : $breakStart;
        $overlapEnd = $checkOut->lessThan($breakEnd) ? $checkOut : $breakEnd;

        if ($overlapEnd->lte($overlapStart)) {
            return 0;
        }

        return $this->wholeMinutesBetween($overlapStart, $overlapEnd);
    }

    private function workedMinutes(
        CarbonInterface $attendanceDate,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
    ): int {
        return max(
            $this->wholeMinutesBetween($checkIn, $checkOut) - $this->breakMinutes($attendanceDate, $checkIn, $checkOut),
            0,
        );
    }

    private function wholeMinutesBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return (int) floor(abs($start->diffInSeconds($end)) / 60);
    }

    private function parseDateTime(null|string|CarbonInterface $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::parse($value);
        }

        return Carbon::parse($value);
    }

    private function deriveTodayAttendanceStatus(?Attendance $attendance): string
    {
        if ($attendance === null || $attendance->check_in === null) {
            return AttendanceStatus::NotCheckedIn;
        }

        if ($attendance->check_out === null) {
            return AttendanceStatus::CheckedIn;
        }

        return AttendanceStatus::CheckedOut;
    }

    private function nextAction(?Attendance $attendance): string
    {
        return match ($this->deriveTodayAttendanceStatus($attendance)) {
            AttendanceStatus::NotCheckedIn => 'check_in',
            AttendanceStatus::CheckedIn => 'check_out',
            default => 'none',
        };
    }

    /**
     * @return array{
     *     presentDays: int,
     *     lateCount: int,
     *     absentCount: int,
     *     workedMinutes: int,
     *     overtimeMinutes: int
     * }
     */
    private function employeeRangeSummary(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $query = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()]);

        return [
            'presentDays' => (clone $query)
                ->whereNotNull('check_in')
                ->count(),
            'lateCount' => (clone $query)
                ->where('late_minutes', '>', 0)
                ->count(),
            'absentCount' => (clone $query)
                ->where('status', AttendanceStatus::Absent)
                ->count(),
            'workedMinutes' => (int) ((clone $query)->sum('worked_minutes')),
            'overtimeMinutes' => (int) ((clone $query)->sum('overtime_minutes')),
        ];
    }

    private function activeEmployeesQuery(): Builder
    {
        return Employee::query()->where('status', 'active');
    }

    private function outageRecoveryCandidateQuery(CarbonInterface $date): Builder
    {
        return $this->activeEmployeesQuery()
            ->whereDoesntHave('attendances', function (Builder $query) use ($date): void {
                $query->whereDate('attendance_date', $date->toDateString());
            })
            ->whereDoesntHave('leaveRequests', function (Builder $query) use ($date): void {
                $this->applyApprovedLeaveFilter($query, $date);
            });
    }

    private function outageRecoveryEmployeesOnLeaveQuery(CarbonInterface $date): Builder
    {
        return $this->activeEmployeesQuery()
            ->whereDoesntHave('attendances', function (Builder $query) use ($date): void {
                $query->whereDate('attendance_date', $date->toDateString());
            })
            ->whereHas('leaveRequests', function (Builder $query) use ($date): void {
                $this->applyApprovedLeaveFilter($query, $date);
            });
    }

    private function outageRecoveryEmployeesWithAttendanceQuery(CarbonInterface $date): Builder
    {
        return $this->activeEmployeesQuery()
            ->whereHas('attendances', function (Builder $query) use ($date): void {
                $query->whereDate('attendance_date', $date->toDateString());
            });
    }

    private function applyApprovedLeaveFilter(Builder $query, CarbonInterface $date): void
    {
        $query->where('status', 'hr_approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString());
    }

    private function assertEmployeeNotOnApprovedLeave(Employee $employee, CarbonInterface $date): void
    {
        $isOnApprovedLeave = $employee->leaveRequests()
            ->where(function (Builder $query) use ($date): void {
                $this->applyApprovedLeaveFilter($query, $date);
            })
            ->exists();

        if ($isOnApprovedLeave) {
            throw ValidationException::withMessages([
                'attendance' => ['You cannot record attendance while on approved leave.'],
            ]);
        }
    }

    /**
     * @param  array{search?: string, department_id?: int}  $filters
     */
    private function applyOutageRecoveryEmployeeFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                filled($filters['search'] ?? null),
                function (Builder $employeeQuery) use ($filters): void {
                    $search = mb_strtolower(trim((string) $filters['search']));

                    $employeeQuery->where(function (Builder $searchQuery) use ($search): void {
                        $pattern = '%'.$search.'%';

                        $searchQuery->whereRaw('LOWER(first_name) LIKE ?', [$pattern])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$pattern])
                            ->orWhereRaw("LOWER(TRIM(first_name || ' ' || last_name)) LIKE ?", [$pattern])
                            ->orWhereRaw('LOWER(employee_code) LIKE ?', [$pattern]);
                    });
                }
            )
            ->when(
                isset($filters['department_id']),
                fn (Builder $employeeQuery): Builder => $employeeQuery->where('department_id', $filters['department_id'])
            );
    }

    private function outageRecoveryDefaultNotes(): string
    {
        return 'System outage recovery';
    }

    private function employeesOnLeaveTodayCount(): int
    {
        return Employee::query()
            ->where('status', 'active')
            ->whereHas('leaveRequests', function (Builder $query): void {
                $this->applyApprovedLeaveFilter($query, today());
            })
            ->count();
    }

    /**
     * @return array{
     *     id: int,
     *     employeeId: int,
     *     attendanceDate: string,
     *     checkInTime: string|null,
     *     checkOutTime: string|null,
     *     checkInAt: string|null,
     *     checkOutAt: string|null,
     *     workedMinutes: int,
     *     status: string,
     *     lateMinutes: int,
     *     earlyLeaveMinutes: int,
     *     overtimeMinutes: int,
     *     source: string|null,
     *     notes: string|null,
     *     correctionReason: string|null,
     *     correctionStatus: string|null,
     *     createdAt: string|null,
     *     updatedAt: string|null,
     *     employee?: array<string, mixed>,
     *     audit?: array<string, mixed>
     * }
     */
    private function transformAttendance(
        Attendance $attendance,
        bool $includeEmployee = false,
        bool $includeAudit = false,
    ): array {
        $payload = [
            'id' => $attendance->id,
            'employeeId' => $attendance->employee_id,
            'attendanceDate' => $attendance->attendance_date?->toDateString() ?? '',
            'checkInTime' => $this->formatTime($attendance->check_in),
            'checkOutTime' => $this->formatTime($attendance->check_out),
            'checkInAt' => $attendance->check_in?->toIso8601String(),
            'checkOutAt' => $attendance->check_out?->toIso8601String(),
            'workedMinutes' => (int) $attendance->worked_minutes,
            'status' => $attendance->status,
            'lateMinutes' => (int) $attendance->late_minutes,
            'earlyLeaveMinutes' => (int) $attendance->early_leave_minutes,
            'overtimeMinutes' => (int) $attendance->overtime_minutes,
            'source' => $attendance->source,
            'notes' => $attendance->notes,
            'correctionReason' => $attendance->correction_reason,
            'correctionStatus' => $attendance->correction_status,
            'createdAt' => $attendance->created_at?->toIso8601String(),
            'updatedAt' => $attendance->updated_at?->toIso8601String(),
        ];

        if ($includeEmployee) {
            $payload['employee'] = $this->transformEmployee($attendance->employee?->loadMissing('department'));
        }

        if ($includeAudit) {
            $payload['audit'] = [
                'createdBy' => $this->transformActor($attendance->creator),
                'updatedBy' => $this->transformActor($attendance->updater),
                'correctedBy' => $this->transformActor($attendance->corrector),
                'editedBy' => $this->transformActor($attendance->editor),
            ];
        }

        return $payload;
    }

    /**
     * @return array{id: int, name: string, department: string|null}|null
     */
    private function transformEmployee(?Employee $employee): ?array
    {
        if (! $employee instanceof Employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'department' => $employee->department?->name,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     employeeCode: string|null,
     *     name: string,
     *     department: string|null,
     *     currentPosition: string|null,
     *     selected: bool,
     *     skipReason: string|null
     * }
     */
    private function transformOutageRecoveryEmployee(
        Employee $employee,
        bool $selected,
        ?string $skipReason = null,
    ): array {
        return [
            'id' => $employee->id,
            'employeeCode' => $employee->employee_code,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'department' => $employee->department?->name,
            'currentPosition' => $employee->currentPosition?->title,
            'selected' => $selected,
            'skipReason' => $skipReason,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function transformActor(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     attendanceId: int|null,
     *     attendanceDate: string|null,
     *     requestDate: string|null,
     *     employee: array{id: int, name: string, department: string|null}|null,
     *     requestedCheckInTime: string|null,
     *     requestedCheckOutTime: string|null,
     *     reason: string,
     *     status: string,
     *     reviewNote: string|null,
     *     reviewedAt: string|null,
     *     reviewedBy: array{id: int, name: string}|null,
     *     createdAt: string|null,
     *     updatedAt: string|null,
     *     attendance: array<string, mixed>|null
     * }
     */
    private function transformCorrectionRequest(AttendanceCorrectionRequest $correctionRequest): array
    {
        return [
            'id' => $correctionRequest->id,
            'attendanceId' => $correctionRequest->attendance_id,
            'attendanceDate' => $this->correctionRequestAttendanceDateOrNull($correctionRequest),
            'requestDate' => $this->correctionRequestAttendanceDateOrNull($correctionRequest),
            'employee' => $this->transformEmployee($correctionRequest->employee?->loadMissing('department')),
            'requestedCheckInTime' => $this->formatClockTime($correctionRequest->requested_check_in_time),
            'requestedCheckOutTime' => $this->formatClockTime($correctionRequest->requested_check_out_time),
            'reason' => $correctionRequest->reason,
            'status' => $correctionRequest->status,
            'reviewNote' => $correctionRequest->review_note,
            'reviewedAt' => $correctionRequest->reviewed_at?->toIso8601String(),
            'reviewedBy' => $this->transformActor($correctionRequest->reviewer),
            'createdAt' => $correctionRequest->created_at?->toIso8601String(),
            'updatedAt' => $correctionRequest->updated_at?->toIso8601String(),
            'attendance' => $correctionRequest->attendance instanceof Attendance
                ? $this->transformAttendance($correctionRequest->attendance, includeEmployee: true)
                : null,
        ];
    }

    private function correctionRequestAttendanceDateOrNull(AttendanceCorrectionRequest $correctionRequest): ?string
    {
        return ($correctionRequest->attendance?->attendance_date instanceof CarbonInterface)
            ? $correctionRequest->attendance->attendance_date->toDateString()
            : ($correctionRequest->requested_check_in_time?->toDateString()
                ?? $correctionRequest->requested_check_out_time?->toDateString());
    }

    private function formatTime(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->format('H:i:s');
    }

    private function formatClockTime(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->format('H:i');
    }

    private function parseClockTimeOnDate(CarbonInterface $date, ?string $time): ?Carbon
    {
        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $date->toDateString().' '.trim($time));
    }
}
