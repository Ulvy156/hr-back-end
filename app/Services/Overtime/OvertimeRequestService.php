<?php

namespace App\Services\Overtime;

use App\Models\Employee;
use App\Models\OvertimeRequest;
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

class OvertimeRequestService
{
    private const MAX_DAILY_OVERTIME_MINUTES = 600;

    public function __construct(
        private AuditLogService $auditLogService,
        private PublicHolidayService $publicHolidayService,
    ) {}

    /**
     * @param  array{
     *     overtime_date: string,
     *     start_time: string,
     *     end_time: string,
     *     reason: string
     * }  $data
     * @return array{message: string, data: OvertimeRequest}
     */
    public function store(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $employee, $data): array {
            $employee = Employee::query()
                ->with(['department', 'manager'])
                ->lockForUpdate()
                ->findOrFail($employee->id);

            $this->assertHasDirectManager($employee);

            $overtimeDate = Carbon::createFromFormat('Y-m-d', $data['overtime_date'])->startOfDay();
            [$startAt, $endAt] = $this->timeRangeForDate(
                $overtimeDate,
                $data['start_time'],
                $data['end_time'],
            );
            $minutes = $this->minutesBetween($startAt, $endAt);

            $this->assertNoOverlappingOpenRequest(
                $employee->id,
                $overtimeDate,
                $startAt->format('H:i:s'),
                $endAt->format('H:i:s'),
            );
            $this->assertDailyOvertimeAllowance($employee->id, $overtimeDate, $minutes);

            $overtimeRequest = OvertimeRequest::query()->create([
                'employee_id' => $employee->id,
                'overtime_date' => $overtimeDate->toDateString(),
                'start_time' => $startAt->format('H:i:s'),
                'end_time' => $endAt->format('H:i:s'),
                'reason' => $data['reason'],
                'status' => OvertimeRequestStatus::Pending,
                'approval_stage' => OvertimeApprovalStage::ManagerReview,
                'minutes' => $minutes,
                'overtime_type' => $this->overtimeTypeForDate($overtimeDate),
            ]);

            $overtimeRequest = $this->loadRelations($overtimeRequest->fresh());

            $this->auditLogService->log(
                logName: 'overtime',
                event: 'overtime_request_created',
                description: 'overtime.request_created',
                causer: $authenticatedUser,
                subject: $overtimeRequest,
                properties: [
                    'employee_id' => $overtimeRequest->employee_id,
                    'overtime_date' => $overtimeRequest->overtime_date?->toDateString(),
                    'start_time' => $overtimeRequest->start_time,
                    'end_time' => $overtimeRequest->end_time,
                    'minutes' => $overtimeRequest->minutes,
                    'overtime_type' => $overtimeRequest->overtime_type,
                    'status' => $overtimeRequest->status,
                    'approval_stage' => $overtimeRequest->approval_stage,
                ],
            );

            return [
                'message' => 'Overtime request submitted successfully.',
                'data' => $overtimeRequest,
            ];
        });
    }

    /**
     * @param  array{
     *     employee_id?: int,
     *     status?: string,
     *     approval_stage?: string,
     *     overtime_type?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     per_page?: int
     * }  $filters
     */
    public function paginate(?User $authenticatedUser, array $filters = []): LengthAwarePaginator
    {
        $authenticatedUser = $this->ensureViewer($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $this->scopeVisibleQuery($this->baseQuery(), $authenticatedUser)
            ->when(
                isset($filters['employee_id']),
                fn (Builder $query): Builder => $query->where('employee_id', $filters['employee_id'])
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['approval_stage']),
                fn (Builder $query): Builder => $query->where('approval_stage', $filters['approval_stage'])
            )
            ->when(
                isset($filters['overtime_type']),
                fn (Builder $query): Builder => $query->where('overtime_type', $filters['overtime_type'])
            )
            ->when(
                isset($filters['from_date']),
                fn (Builder $query): Builder => $query->whereDate('overtime_date', '>=', $filters['from_date'])
            )
            ->when(
                isset($filters['to_date']),
                fn (Builder $query): Builder => $query->whereDate('overtime_date', '<=', $filters['to_date'])
            )
            ->orderByDesc('overtime_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function show(?User $authenticatedUser, OvertimeRequest $overtimeRequest): OvertimeRequest
    {
        $authenticatedUser = $this->ensureViewer($authenticatedUser);
        $overtimeRequest = $this->loadRelations($overtimeRequest);
        $this->assertCanView($authenticatedUser, $overtimeRequest);

        return $overtimeRequest;
    }

    /**
     * @return array{message: string, data: OvertimeRequest}
     */
    public function managerApprove(?User $authenticatedUser, OvertimeRequest $overtimeRequest): array
    {
        $authenticatedUser = $this->ensureManagerApprover($authenticatedUser);
        $reviewer = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $reviewer, $overtimeRequest): array {
            $lockedRequest = $this->lockedRequest($overtimeRequest->id);

            $this->assertNotOwnRequest($reviewer, $lockedRequest);

            if (! $this->isDirectReportRequest($reviewer, $lockedRequest)) {
                throw new HttpException(403, 'Forbidden.');
            }

            if ($lockedRequest->status !== OvertimeRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending overtime requests can be approved by a manager.'],
                ]);
            }

            $lockedRequest->forceFill([
                'status' => OvertimeRequestStatus::Approved,
                'approval_stage' => OvertimeApprovalStage::Completed,
                'manager_approved_by' => $reviewer->id,
                'manager_approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $lockedRequest = $this->loadRelations($lockedRequest->fresh());

            $this->auditLogService->log(
                logName: 'overtime',
                event: 'overtime_request_manager_approved',
                description: 'overtime.manager_approved',
                causer: $authenticatedUser,
                subject: $lockedRequest,
                properties: [
                    'employee_id' => $lockedRequest->employee_id,
                    'reviewer_id' => $reviewer->id,
                    'status' => $lockedRequest->status,
                    'approval_stage' => $lockedRequest->approval_stage,
                ],
            );

            return [
                'message' => 'Overtime request approved successfully.',
                'data' => $lockedRequest,
            ];
        });
    }

    /**
     * @param  array{rejection_reason: string}  $data
     * @return array{message: string, data: OvertimeRequest}
     */
    public function reject(?User $authenticatedUser, OvertimeRequest $overtimeRequest, array $data): array
    {
        $authenticatedUser = $this->ensureManagerApprover($authenticatedUser);
        $reviewer = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $reviewer, $overtimeRequest, $data): array {
            $lockedRequest = $this->lockedRequest($overtimeRequest->id);

            $this->assertNotOwnRequest($reviewer, $lockedRequest);

            if (! $this->isDirectReportRequest($reviewer, $lockedRequest)) {
                throw new HttpException(403, 'Forbidden.');
            }

            if ($lockedRequest->status !== OvertimeRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending overtime requests can be rejected.'],
                ]);
            }

            $lockedRequest->forceFill([
                'status' => OvertimeRequestStatus::Rejected,
                'approval_stage' => OvertimeApprovalStage::Completed,
                'rejected_by' => $reviewer->id,
                'rejected_at' => now(),
                'rejection_reason' => $data['rejection_reason'],
            ])->save();

            $lockedRequest = $this->loadRelations($lockedRequest->fresh());

            $this->auditLogService->log(
                logName: 'overtime',
                event: 'overtime_request_manager_rejected',
                description: 'overtime.manager_rejected',
                causer: $authenticatedUser,
                subject: $lockedRequest,
                properties: [
                    'employee_id' => $lockedRequest->employee_id,
                    'reviewer_id' => $reviewer->id,
                    'status' => $lockedRequest->status,
                    'approval_stage' => $lockedRequest->approval_stage,
                    'rejection_reason' => $lockedRequest->rejection_reason,
                ],
            );

            return [
                'message' => 'Overtime request rejected successfully.',
                'data' => $lockedRequest,
            ];
        });
    }

    /**
     * @return array{message: string, data: OvertimeRequest}
     */
    public function cancel(?User $authenticatedUser, OvertimeRequest $overtimeRequest): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $employee, $overtimeRequest): array {
            $lockedRequest = $this->lockedRequest($overtimeRequest->id);

            if ($lockedRequest->employee_id !== $employee->id) {
                throw new HttpException(403, 'Forbidden.');
            }

            if ($lockedRequest->status !== OvertimeRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending overtime requests can be cancelled.'],
                ]);
            }

            $lockedRequest->forceFill([
                'status' => OvertimeRequestStatus::Cancelled,
                'approval_stage' => OvertimeApprovalStage::Completed,
                'manager_approved_by' => null,
                'manager_approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $lockedRequest = $this->loadRelations($lockedRequest->fresh());

            $this->auditLogService->log(
                logName: 'overtime',
                event: 'overtime_request_cancelled',
                description: 'overtime.request_cancelled',
                causer: $authenticatedUser,
                subject: $lockedRequest,
                properties: [
                    'employee_id' => $lockedRequest->employee_id,
                    'status' => $lockedRequest->status,
                    'approval_stage' => $lockedRequest->approval_stage,
                ],
            );

            return [
                'message' => 'Overtime request cancelled successfully.',
                'data' => $lockedRequest,
            ];
        });
    }

    private function baseQuery(): Builder
    {
        return OvertimeRequest::query()->with([
            'employee.department',
            'employee.manager',
            'managerApprover',
            'rejector',
        ]);
    }

    private function loadRelations(OvertimeRequest $overtimeRequest): OvertimeRequest
    {
        return $overtimeRequest->load([
            'employee.department',
            'employee.manager',
            'managerApprover',
            'rejector',
        ]);
    }

    private function lockedRequest(int $overtimeRequestId): OvertimeRequest
    {
        return OvertimeRequest::query()
            ->with([
                'employee.department',
                'employee.manager',
                'managerApprover',
                'rejector',
            ])
            ->lockForUpdate()
            ->findOrFail($overtimeRequestId);
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
        $employee = $authenticatedUser->loadMissing('employee.department', 'employee.manager')->employee;

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages([
                'user' => ['The authenticated user is not linked to an employee profile.'],
            ]);
        }

        return $employee;
    }

    private function ensureViewer(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $authenticatedUser->canAny([
            PermissionName::OvertimeRequestViewAny->value,
            PermissionName::OvertimeRequestViewAssigned->value,
            PermissionName::OvertimeRequestViewSelf->value,
        ])) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function ensureManagerApprover(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $this->hasPermission($authenticatedUser, PermissionName::OvertimeApproveManager->value)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->can($permission);
    }

    private function scopeVisibleQuery(Builder $query, User $authenticatedUser): Builder
    {
        if ($this->hasPermission($authenticatedUser, PermissionName::OvertimeRequestViewAny->value)) {
            return $query;
        }

        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $canViewSelf = $this->hasPermission($authenticatedUser, PermissionName::OvertimeRequestViewSelf->value);
        $canViewAssigned = $this->hasPermission($authenticatedUser, PermissionName::OvertimeRequestViewAssigned->value);

        if (! $canViewSelf && ! $canViewAssigned) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $query->where(function (Builder $visibleQuery) use ($canViewAssigned, $canViewSelf, $employee): void {
            if ($canViewSelf) {
                $visibleQuery->where('employee_id', $employee->id);
            }

            if ($canViewAssigned) {
                $method = $canViewSelf ? 'orWhereHas' : 'whereHas';

                $visibleQuery->{$method}(
                    'employee',
                    fn (Builder $employeeQuery): Builder => $employeeQuery->where('manager_id', $employee->id)
                );
            }
        });
    }

    private function assertCanView(User $authenticatedUser, OvertimeRequest $overtimeRequest): void
    {
        if ($this->hasPermission($authenticatedUser, PermissionName::OvertimeRequestViewAny->value)) {
            return;
        }

        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        if (
            $this->hasPermission($authenticatedUser, PermissionName::OvertimeRequestViewSelf->value)
            && $overtimeRequest->employee_id === $employee->id
        ) {
            return;
        }

        if (
            $this->hasPermission($authenticatedUser, PermissionName::OvertimeRequestViewAssigned->value)
            && $this->isDirectReportRequest($employee, $overtimeRequest)
        ) {
            return;
        }

        throw new HttpException(403, 'Forbidden.');
    }

    private function assertHasDirectManager(Employee $employee): void
    {
        if ($employee->manager_id === null) {
            throw ValidationException::withMessages([
                'employee' => ['A direct manager must be assigned before an overtime request can be submitted.'],
            ]);
        }

        if ($employee->manager_id === $employee->id) {
            throw ValidationException::withMessages([
                'employee' => ['The manager configuration is invalid. You cannot approve your own overtime request.'],
            ]);
        }
    }

    private function assertNotOwnRequest(Employee $reviewer, OvertimeRequest $overtimeRequest): void
    {
        if ($overtimeRequest->employee_id === $reviewer->id) {
            throw ValidationException::withMessages([
                'overtime_request' => ['You cannot approve or reject your own overtime request.'],
            ]);
        }
    }

    private function isDirectReportRequest(Employee $reviewer, OvertimeRequest $overtimeRequest): bool
    {
        return $overtimeRequest->employee?->manager_id === $reviewer->id;
    }

    private function assertNoOverlappingOpenRequest(
        int $employeeId,
        CarbonInterface $overtimeDate,
        string $startTime,
        string $endTime,
        ?int $ignoreRequestId = null,
    ): void {
        $exists = OvertimeRequest::query()
            ->where('employee_id', $employeeId)
            ->whereDate('overtime_date', $overtimeDate->toDateString())
            ->whereIn('status', [
                OvertimeRequestStatus::Pending,
                OvertimeRequestStatus::Approved,
            ])
            ->when(
                $ignoreRequestId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($ignoreRequestId)
            )
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_time' => ['An overlapping pending or approved overtime request already exists for this employee and time range.'],
            ]);
        }
    }

    private function assertDailyOvertimeAllowance(
        int $employeeId,
        CarbonInterface $overtimeDate,
        int $requestedMinutes,
    ): void {
        $existingMinutes = (int) OvertimeRequest::query()
            ->where('employee_id', $employeeId)
            ->whereDate('overtime_date', $overtimeDate->toDateString())
            ->whereIn('status', [
                OvertimeRequestStatus::Pending,
                OvertimeRequestStatus::Approved,
            ])
            ->sum('minutes');

        $totalMinutes = $existingMinutes + $requestedMinutes;

        if ($totalMinutes > self::MAX_DAILY_OVERTIME_MINUTES) {
            throw ValidationException::withMessages([
                'minutes' => ['Total requested overtime for this day cannot exceed 10 hours.'],
            ]);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function timeRangeForDate(CarbonInterface $overtimeDate, string $startTime, string $endTime): array
    {
        $startAt = Carbon::createFromFormat('Y-m-d H:i:s', $overtimeDate->toDateString().' '.$startTime);
        $endAt = Carbon::createFromFormat('Y-m-d H:i:s', $overtimeDate->toDateString().' '.$endTime);

        if ($endAt->lte($startAt)) {
            throw ValidationException::withMessages([
                'end_time' => ['The end time must be later than the start time.'],
            ]);
        }

        return [$startAt, $endAt];
    }

    private function minutesBetween(CarbonInterface $startAt, CarbonInterface $endAt): int
    {
        return max($startAt->diffInMinutes($endAt, false), 0);
    }

    private function overtimeTypeForDate(CarbonInterface $overtimeDate): string
    {
        $dateString = $overtimeDate->toDateString();

        if (in_array($dateString, $this->publicHolidayService->holidayDatesBetween($overtimeDate, $overtimeDate), true)) {
            return OvertimeType::Holiday;
        }

        if ($overtimeDate->isWeekend()) {
            return OvertimeType::Weekend;
        }

        return OvertimeType::Normal;
    }
}
