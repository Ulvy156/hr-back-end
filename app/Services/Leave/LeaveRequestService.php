<?php

namespace App\Services\Leave;

use App\EmployeeGender;
use App\EmploymentType;
use App\LeaveTypeCode;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PublicHoliday\PublicHolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class LeaveRequestService
{
    private const HR_APPROVAL_PERMISSION = 'leave.approve.hr';

    private const CEO_POSITION_TITLE = 'chief executive officer';

    public function __construct(
        private AuditLogService $auditLogService,
        private PublicHolidayService $publicHolidayService,
    ) {}

    /**
     * @param  array{
     *     type: string,
     *     start_date: string,
     *     end_date: string,
     *     reason: string,
     *     duration_type: string,
     *     half_day_session?: string|null
     * }  $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function store(?User $authenticatedUser, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        $this->assertHasValidLeaveApprover($employee);

        return DB::transaction(function () use ($authenticatedUser, $employee, $data): array {
            $startDate = Carbon::createFromFormat('Y-m-d', $data['start_date'])->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', $data['end_date'])->startOfDay();

            /** @var LeaveType $leaveType */
            $leaveType = LeaveType::query()
                ->where('code', $data['type'])
                ->where('is_active', true)
                ->firstOrFail();

            $employee = Employee::query()
                ->with(['department', 'manager', 'leaveApprover'])
                ->lockForUpdate()
                ->findOrFail($employee->id);

            $this->assertHasValidLeaveApprover($employee);
            $this->assertLeaveTypeEligibleForEmployee($leaveType, $employee, $startDate);
            $this->assertNoOverlappingOpenLeaveRequest($employee->id, $startDate, $endDate);
            $this->assertDurationTypeAllowedForLeaveType($leaveType, $data['duration_type']);

            $requestedDays = $this->calculateRequestedDays(
                $leaveType,
                $startDate,
                $endDate,
                $data['duration_type'],
            );

            if ($requestedDays <= 0) {
                throw ValidationException::withMessages([
                    'end_date' => ['The selected date range does not include any leave days after policy exclusions.'],
                ]);
            }

            $this->assertLeaveNoticeRequirement($leaveType, $startDate, $requestedDays);
            $this->assertLeavePolicyAllowsDuration(
                $employee,
                $leaveType,
                $startDate,
                $endDate,
                $requestedDays,
                $data['duration_type'],
            );

            $leaveRequest = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'type' => $leaveType->code,
                'reason' => $data['reason'],
                'duration_type' => $data['duration_type'],
                'half_day_session' => $data['duration_type'] === LeaveRequestDurationType::HalfDay
                    ? $data['half_day_session']
                    : null,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'status' => LeaveRequestStatus::Pending,
            ]);

            $leaveRequest = $this->loadLeaveRequestRelations($leaveRequest->fresh());

            $this->auditLogService->log(
                logName: 'leave',
                event: 'leave_request_created',
                description: 'leave.request_created',
                causer: $authenticatedUser,
                subject: $leaveRequest,
                properties: [
                    'employee_id' => $employee->id,
                    'type' => $leaveRequest->type,
                    'reason' => $leaveRequest->reason,
                    'duration_type' => $leaveRequest->duration_type,
                    'half_day_session' => $leaveRequest->half_day_session,
                    'start_date' => $leaveRequest->start_date?->toDateString(),
                    'end_date' => $leaveRequest->end_date?->toDateString(),
                    'requested_days' => $requestedDays,
                    'status' => $leaveRequest->status,
                ],
            );

            return [
                'message' => 'Leave request submitted successfully.',
                'data' => $this->transformLeaveRequest($leaveRequest, $authenticatedUser),
            ];
        });
    }

    /**
     * @param  array{employee_id?: int, type?: string, status?: string, from_date?: string, to_date?: string, per_page?: int}  $filters
     */
    public function myHistory(?User $authenticatedUser, array $filters = []): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = $this->baseQuery()
            ->where('employee_id', $employee->id)
            ->when(
                isset($filters['type']),
                fn (Builder $query): Builder => $query->where('type', $filters['type'])
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['from_date']),
                fn (Builder $query): Builder => $query->whereDate('start_date', '>=', $filters['from_date'])
            )
            ->when(
                isset($filters['to_date']),
                fn (Builder $query): Builder => $query->whereDate('end_date', '<=', $filters['to_date'])
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->paginateLeaveRequests($query, $perPage, $authenticatedUser);
    }

    /**
     * @param  array{employee_id?: int, type?: string, status?: string, from_date?: string, to_date?: string, per_page?: int}  $filters
     */
    public function index(?User $authenticatedUser, array $filters = []): array
    {
        $authenticatedUser = $this->ensureManagementReader($authenticatedUser);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $this->paginateLeaveRequests(
            $this->scopedQueryForReviewer($authenticatedUser, $filters),
            $perPage,
            $authenticatedUser,
        );
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function show(?User $authenticatedUser, LeaveRequest $leaveRequest): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $leaveRequest = $this->loadLeaveRequestRelations($leaveRequest);
        $this->assertCanViewLeaveRequest($authenticatedUser, $leaveRequest);

        return [
            'data' => $this->transformLeaveRequest($leaveRequest, $authenticatedUser),
        ];
    }

    /**
     * @return array{message: string, data: array<string, mixed>}
     */
    public function cancel(?User $authenticatedUser, LeaveRequest $leaveRequest): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $employee, $leaveRequest): array {
            /** @var LeaveRequest $lockedLeaveRequest */
            $lockedLeaveRequest = LeaveRequest::query()
                ->with(['employee.department', 'employee.manager', 'leaveType', 'managerApprover', 'hrApprover'])
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            if ($lockedLeaveRequest->employee_id !== $employee->id) {
                throw new HttpException(403, 'Forbidden.');
            }

            if (! in_array($lockedLeaveRequest->status, [
                LeaveRequestStatus::Pending,
                LeaveRequestStatus::ManagerApproved,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or manager-approved leave requests can be cancelled.'],
                ]);
            }

            $lockedLeaveRequest->forceFill([
                'status' => LeaveRequestStatus::Cancelled,
                'manager_approved_by' => null,
                'manager_approved_at' => null,
                'hr_approved_by' => null,
                'hr_approved_at' => null,
            ])->save();

            $lockedLeaveRequest = $this->loadLeaveRequestRelations($lockedLeaveRequest->fresh());

            $this->auditLogService->log(
                logName: 'leave',
                event: 'leave_request_cancelled',
                description: 'leave.request_cancelled',
                causer: $authenticatedUser,
                subject: $lockedLeaveRequest,
                properties: [
                    'employee_id' => $lockedLeaveRequest->employee_id,
                    'status' => $lockedLeaveRequest->status,
                ],
            );

            return [
                'message' => 'Leave request cancelled successfully.',
                'data' => $this->transformLeaveRequest($lockedLeaveRequest, $authenticatedUser),
            ];
        });
    }

    /**
     * @param  array{status: string}  $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function managerReview(?User $authenticatedUser, LeaveRequest $leaveRequest, array $data): array
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);
        $reviewer = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $reviewer, $leaveRequest, $data): array {
            /** @var LeaveRequest $lockedLeaveRequest */
            $lockedLeaveRequest = LeaveRequest::query()
                ->with(['employee.department', 'employee.manager', 'leaveType', 'managerApprover', 'hrApprover'])
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            if ($lockedLeaveRequest->employee_id === $reviewer->id) {
                throw ValidationException::withMessages([
                    'leave_request' => ['You cannot approve or reject your own leave request.'],
                ]);
            }

            if (! $this->isAssignedLeaveApprover($reviewer, $lockedLeaveRequest)) {
                throw new HttpException(403, 'Forbidden.');
            }

            if ($lockedLeaveRequest->status !== LeaveRequestStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending leave requests can be reviewed by a manager.'],
                ]);
            }

            $approved = $data['status'] === LeaveRequestStatus::ManagerApproved;
            $finalApproverApproval = $approved && $this->managerApprovalFinalizesWorkflow($lockedLeaveRequest, $reviewer);
            $approvalTimestamp = $approved ? now() : null;

            if ($finalApproverApproval) {
                $this->assertLeaveRequestCanBeFinallyApproved($lockedLeaveRequest);
            }

            $lockedLeaveRequest->forceFill([
                'status' => $finalApproverApproval ? LeaveRequestStatus::HrApproved : $data['status'],
                'manager_approved_by' => $approved ? $reviewer->id : null,
                'manager_approved_at' => $approvalTimestamp,
                'hr_approved_by' => $finalApproverApproval ? $reviewer->id : null,
                'hr_approved_at' => $finalApproverApproval ? $approvalTimestamp : null,
            ])->save();

            $lockedLeaveRequest = $this->loadLeaveRequestRelations($lockedLeaveRequest->fresh());

            $this->auditLogService->log(
                logName: 'leave',
                event: ! $approved
                    ? 'leave_request_manager_rejected'
                    : ($finalApproverApproval
                        ? 'leave_request_final_approver_approved'
                        : 'leave_request_manager_approved'),
                description: ! $approved
                    ? 'leave.manager_rejected'
                    : ($finalApproverApproval
                        ? 'leave.final_approver_approved'
                        : 'leave.manager_approved'),
                causer: $authenticatedUser,
                subject: $lockedLeaveRequest,
                properties: [
                    'employee_id' => $lockedLeaveRequest->employee_id,
                    'reviewer_id' => $reviewer->id,
                    'manager_id' => $reviewer->id,
                    'hr_id' => $finalApproverApproval ? $reviewer->id : null,
                    'status' => $lockedLeaveRequest->status,
                    'acted_as_roles' => ! $approved
                        ? ['manager']
                        : ($finalApproverApproval ? ['manager', 'final_approver'] : ['manager']),
                    'approval_roles_satisfied' => ! $approved
                        ? []
                        : ($finalApproverApproval ? ['manager', 'hr'] : ['manager']),
                    'dual_role_approval' => false,
                    'final_approver_approval' => $finalApproverApproval,
                ],
            );

            return [
                'message' => ! $approved
                    ? 'Leave request rejected by manager successfully.'
                    : ($finalApproverApproval
                        ? 'Leave request fully approved successfully.'
                        : 'Leave request approved by manager successfully.'),
                'data' => $this->transformLeaveRequest($lockedLeaveRequest, $authenticatedUser),
            ];
        });
    }

    /**
     * @param  array{status: string}  $data
     * @return array{message: string, data: array<string, mixed>}
     */
    public function hrReview(?User $authenticatedUser, LeaveRequest $leaveRequest, array $data): array
    {
        $authenticatedUser = $this->ensureHrOperator($authenticatedUser);
        $reviewer = $this->ensureEmployeeProfile($authenticatedUser);

        return DB::transaction(function () use ($authenticatedUser, $reviewer, $leaveRequest, $data): array {
            /** @var LeaveRequest $lockedLeaveRequest */
            $lockedLeaveRequest = LeaveRequest::query()
                ->with(['employee.department', 'employee.manager', 'leaveType', 'managerApprover', 'hrApprover'])
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            if ($lockedLeaveRequest->employee_id === $reviewer->id) {
                throw ValidationException::withMessages([
                    'leave_request' => ['You cannot approve or reject your own leave request.'],
                ]);
            }

            if ($lockedLeaveRequest->status !== LeaveRequestStatus::ManagerApproved) {
                throw ValidationException::withMessages([
                    'status' => ['HR can only review leave requests after manager approval.'],
                ]);
            }

            if ($data['status'] === LeaveRequestStatus::HrApproved) {
                $this->assertLeaveRequestCanBeFinallyApproved($lockedLeaveRequest);
            }

            $lockedLeaveRequest->forceFill([
                'status' => $data['status'],
                'hr_approved_by' => $data['status'] === LeaveRequestStatus::HrApproved ? $reviewer->id : null,
                'hr_approved_at' => $data['status'] === LeaveRequestStatus::HrApproved ? now() : null,
            ])->save();

            $lockedLeaveRequest = $this->loadLeaveRequestRelations($lockedLeaveRequest->fresh());
            $approved = $data['status'] === LeaveRequestStatus::HrApproved;

            $this->auditLogService->log(
                logName: 'leave',
                event: $approved ? 'leave_request_hr_approved' : 'leave_request_hr_rejected',
                description: $approved ? 'leave.hr_approved' : 'leave.hr_rejected',
                causer: $authenticatedUser,
                subject: $lockedLeaveRequest,
                properties: [
                    'employee_id' => $lockedLeaveRequest->employee_id,
                    'reviewer_id' => $reviewer->id,
                    'hr_id' => $reviewer->id,
                    'status' => $lockedLeaveRequest->status,
                    'acted_as_roles' => ['hr'],
                    'approval_roles_satisfied' => $approved ? ['manager', 'hr'] : ['manager'],
                    'dual_role_approval' => false,
                ],
            );

            return [
                'message' => $approved
                    ? 'Leave request approved by HR successfully.'
                    : 'Leave request rejected by HR successfully.',
                'data' => $this->transformLeaveRequest($lockedLeaveRequest, $authenticatedUser),
            ];
        });
    }

    /**
     * @return array{
     *     supports_half_day: bool,
     *     supported_half_day_sessions: array<int, string>,
     *     notice_rules: array<int, array{leave_days_gt: int, minimum_notice_days: int}>,
     *     notice_rule_text: string|null,
     *     is_requestable: bool,
     *     request_restriction_reason: string|null,
     *     balance_snapshot: array{year: int, entitlement_days: float, used_days: int, reserved_days: int, available_days: float}|null
     * }
     */
    public function leaveTypeUiMetadata(
        LeaveType $leaveType,
        ?Employee $employee = null,
        ?CarbonInterface $referenceDate = null,
    ): array {
        $referenceDate = $referenceDate?->copy()->startOfDay() ?? today()->startOfDay();

        $requestability = $employee instanceof Employee
            ? $this->leaveTypeRequestability($leaveType, $employee, $referenceDate)
            : ['is_requestable' => true, 'request_restriction_reason' => null];

        return [
            'supports_half_day' => $this->leaveTypeSupportsHalfDay($leaveType),
            'supported_half_day_sessions' => $this->leaveTypeSupportsHalfDay($leaveType)
                ? LeaveRequestHalfDaySession::all()
                : [],
            'notice_rules' => $this->leaveTypeNoticeRules($leaveType),
            'notice_rule_text' => $this->leaveTypeNoticeRuleText($leaveType),
            'is_requestable' => $requestability['is_requestable'],
            'request_restriction_reason' => $requestability['request_restriction_reason'],
            'balance_snapshot' => $employee instanceof Employee && $leaveType->requires_balance
                ? $this->balanceSummaryForYear($employee, $leaveType, $referenceDate->year, $referenceDate)
                : null,
        ];
    }

    public function currentEmployeeProfile(?User $authenticatedUser): Employee
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        return $this->ensureEmployeeProfile($authenticatedUser);
    }

    /**
     * @return array{
     *     year: int,
     *     total_days: float|null,
     *     used_days: float,
     *     remaining_days: float|null
     * }
     */
    public function currentLeaveBalanceSnapshot(
        Employee $employee,
        LeaveType $leaveType,
        ?CarbonInterface $referenceDate = null,
    ): array {
        $referenceDate = $referenceDate?->copy()->startOfDay() ?? today()->startOfDay();
        $year = $referenceDate->year;
        $usedDays = $this->approvedLeaveDaysForYear($employee, $leaveType, $year);
        $totalDays = $this->leaveTypeTotalDaysForYear($employee, $leaveType, $year, $referenceDate);

        return [
            'year' => $year,
            'total_days' => $totalDays,
            'used_days' => round($usedDays, 1),
            'remaining_days' => $totalDays === null
                ? null
                : max(round($totalDays - $usedDays, 1), 0),
        ];
    }

    /**
     * @param  array{employee_id?: int, type?: string, status?: string, from_date?: string, to_date?: string}  $filters
     */
    private function scopedQueryForReviewer(User $authenticatedUser, array $filters = []): Builder
    {
        $query = $this->baseQuery()
            ->when(
                isset($filters['employee_id']),
                fn (Builder $builder): Builder => $builder->where('employee_id', $filters['employee_id'])
            )
            ->when(
                isset($filters['type']),
                fn (Builder $builder): Builder => $builder->where('type', $filters['type'])
            )
            ->when(
                isset($filters['status']),
                fn (Builder $builder): Builder => $builder->where('status', $filters['status'])
            )
            ->when(
                isset($filters['from_date']),
                fn (Builder $builder): Builder => $builder->whereDate('start_date', '>=', $filters['from_date'])
            )
            ->when(
                isset($filters['to_date']),
                fn (Builder $builder): Builder => $builder->whereDate('end_date', '<=', $filters['to_date'])
            );

        if ($this->canReviewAllLeaveRequests($authenticatedUser)) {
            return $query
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        $reviewer = $this->ensureEmployeeProfile($authenticatedUser);

        return $query
            ->whereHas(
                'employee',
                fn (Builder $employeeQuery): Builder => $employeeQuery->where(function (Builder $employeeScope) use ($reviewer): Builder {
                    return $employeeScope
                        ->where('leave_approver_id', $reviewer->id)
                        ->orWhere(function (Builder $fallbackScope) use ($reviewer): Builder {
                            return $fallbackScope
                                ->whereNull('leave_approver_id')
                                ->where('manager_id', $reviewer->id);
                        });
                })
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function baseQuery(): Builder
    {
        return LeaveRequest::query()
            ->with([
                'employee.department',
                'employee.manager',
                'employee.leaveApprover',
                'leaveType',
                'managerApprover',
                'hrApprover',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginateLeaveRequests(
        Builder $query,
        int $perPage,
        ?User $authenticatedUser = null,
    ): array {
        $summary = $this->leaveRequestSummary(clone $query);
        $paginator = $query
            ->paginate($perPage)
            ->through(fn (LeaveRequest $leaveRequest): array => $this->transformLeaveRequest($leaveRequest, $authenticatedUser));

        return [
            ...$paginator->toArray(),
            'summary' => $summary,
        ];
    }

    /**
     * @return array{
     *     total_requests: int,
     *     pending_count: int,
     *     approved_count: int,
     *     rejected_count: int,
     *     cancelled_count: int
     * }
     */
    private function leaveRequestSummary(Builder $query): array
    {
        return [
            'total_requests' => (clone $query)->count(),
            'pending_count' => (clone $query)->where('status', LeaveRequestStatus::Pending)->count(),
            'approved_count' => (clone $query)->where('status', LeaveRequestStatus::HrApproved)->count(),
            'rejected_count' => (clone $query)->where('status', LeaveRequestStatus::Rejected)->count(),
            'cancelled_count' => (clone $query)->where('status', LeaveRequestStatus::Cancelled)->count(),
        ];
    }

    private function loadLeaveRequestRelations(?LeaveRequest $leaveRequest): LeaveRequest
    {
        if (! $leaveRequest instanceof LeaveRequest) {
            throw ValidationException::withMessages([
                'leave_request' => ['The requested leave record could not be found.'],
            ]);
        }

        return $leaveRequest->loadMissing([
            'employee.department',
            'employee.manager',
            'leaveType',
            'managerApprover',
            'hrApprover',
        ]);
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
        $employee = $authenticatedUser->loadMissing('employee.department', 'employee.manager', 'employee.leaveApprover')->employee;

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

        if (
            ! $this->hasAnyRole($authenticatedUser, ['manager', 'hr', 'admin'])
            && ! $this->hasHrApprovalAuthority($authenticatedUser)
        ) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function ensureHrOperator(?User $authenticatedUser): User
    {
        $authenticatedUser = $this->ensureAuthenticated($authenticatedUser);

        if (! $this->hasHrApprovalAuthority($authenticatedUser)) {
            throw new HttpException(403, 'Forbidden.');
        }

        return $authenticatedUser;
    }

    private function hasRole(User $user, string $role): bool
    {
        return $user->loadMissing('roles')->roles->contains('name', $role);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return $user->loadMissing('roles')->roles->pluck('name')->intersect($roles)->isNotEmpty();
    }

    private function hasHrApprovalAuthority(User $user): bool
    {
        return $this->hasPermission($user, self::HR_APPROVAL_PERMISSION);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->loadMissing('roles.permissions')->roles
            ->flatMap(fn (mixed $role): Collection => $role->permissions)
            ->contains('name', $permission);
    }

    private function canReviewAllLeaveRequests(User $authenticatedUser): bool
    {
        return $this->hasRole($authenticatedUser, 'admin')
            || $this->hasRole($authenticatedUser, 'hr')
            || $this->hasHrApprovalAuthority($authenticatedUser);
    }

    private function leaveApproverIdFor(?Employee $employee): ?int
    {
        return $employee?->leave_approver_id ?? $employee?->manager_id;
    }

    private function assertHasValidLeaveApprover(Employee $employee): int
    {
        $approverId = $this->leaveApproverIdFor($employee);

        if ($approverId === null) {
            throw ValidationException::withMessages([
                'employee' => ['A leave approver or manager must be assigned before a leave request can be submitted.'],
            ]);
        }

        if ($approverId === $employee->id) {
            throw ValidationException::withMessages([
                'employee' => ['The leave approver configuration is invalid. You cannot approve your own leave request.'],
            ]);
        }

        return $approverId;
    }

    private function isAssignedLeaveApprover(Employee $reviewer, LeaveRequest $leaveRequest): bool
    {
        return $this->leaveApproverIdFor($leaveRequest->employee) === $reviewer->id;
    }

    private function managerApprovalFinalizesWorkflow(LeaveRequest $leaveRequest, Employee $reviewer): bool
    {
        if ($this->approvedByChiefExecutiveOfficerAsDirectManager($leaveRequest, $reviewer)) {
            return true;
        }

        return ! $this->leaveRequestRequiresHrReview($leaveRequest);
    }

    private function leaveRequestRequiresHrReview(LeaveRequest $leaveRequest): bool
    {
        return ! $this->employeeQualifiesForFinalApproverWorkflow($leaveRequest->employee);
    }

    private function employeeQualifiesForFinalApproverWorkflow(?Employee $employee): bool
    {
        if (! $employee instanceof Employee) {
            return false;
        }

        if ($this->employeeBelongsToHrLayer($employee)) {
            return true;
        }

        return $this->employeeHasDirectReports($employee);
    }

    private function employeeBelongsToHrLayer(?Employee $employee): bool
    {
        $departmentName = $employee?->department?->name;

        if ($departmentName === null) {
            return false;
        }

        return Str::lower(trim($departmentName)) === 'human resources';
    }

    private function employeeHasDirectReports(Employee $employee): bool
    {
        return $employee->relationLoaded('subordinates')
            ? $employee->subordinates->isNotEmpty()
            : $employee->subordinates()->exists();
    }

    private function approvedByChiefExecutiveOfficerAsDirectManager(
        LeaveRequest $leaveRequest,
        Employee $reviewer,
    ): bool {
        $employee = $leaveRequest->employee;

        if (! $employee instanceof Employee) {
            return false;
        }

        if ($employee->manager_id !== $reviewer->id) {
            return false;
        }

        return $this->employeeIsChiefExecutiveOfficer($reviewer);
    }

    private function employeeIsChiefExecutiveOfficer(Employee $employee): bool
    {
        $positionTitle = $employee->loadMissing('currentPosition')->currentPosition?->title;

        if (! is_string($positionTitle) || trim($positionTitle) === '') {
            return false;
        }

        return Str::lower(trim($positionTitle)) === self::CEO_POSITION_TITLE;
    }

    private function assertCanViewLeaveRequest(User $authenticatedUser, LeaveRequest $leaveRequest): void
    {
        if ($this->canReviewAllLeaveRequests($authenticatedUser)) {
            return;
        }

        $employee = $this->ensureEmployeeProfile($authenticatedUser);

        if ($leaveRequest->employee_id === $employee->id) {
            return;
        }

        if ($this->isAssignedLeaveApprover($employee, $leaveRequest)) {
            return;
        }

        throw new HttpException(403, 'Forbidden.');
    }

    private function assertLeaveRequestCanBeFinallyApproved(LeaveRequest $leaveRequest): void
    {
        $leaveType = $leaveRequest->leaveType;

        if (! $leaveType instanceof LeaveType) {
            throw ValidationException::withMessages([
                'type' => ['The leave type for this request is invalid.'],
            ]);
        }

        if (! $leaveRequest->employee instanceof Employee) {
            throw ValidationException::withMessages([
                'employee' => ['The employee linked to this leave request could not be resolved.'],
            ]);
        }

        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $durationType = $this->leaveRequestDurationType($leaveRequest);
        $requestedDays = $this->calculateRequestedDays(
            $leaveType,
            $startDate,
            $endDate,
            $durationType,
        );

        $this->assertLeavePolicyAllowsDuration(
            $leaveRequest->employee,
            $leaveType,
            $startDate,
            $endDate,
            $requestedDays,
            $durationType,
            $leaveRequest->id,
        );
    }

    private function assertNoOverlappingOpenLeaveRequest(
        int $employeeId,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        ?int $ignoreLeaveRequestId = null,
    ): void {
        $query = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [
                LeaveRequestStatus::Pending,
                LeaveRequestStatus::ManagerApproved,
                LeaveRequestStatus::HrApproved,
            ])
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString());

        if ($ignoreLeaveRequestId !== null) {
            $query->where('id', '!=', $ignoreLeaveRequestId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'start_date' => ['The selected date range overlaps with another open or approved leave request.'],
            ]);
        }
    }

    private function assertLeaveTypeEligibleForEmployee(
        LeaveType $leaveType,
        Employee $employee,
        CarbonInterface $startDate,
    ): void {
        if (
            $leaveType->code === LeaveTypeCode::Annual->value
            && $employee->employment_type === EmploymentType::Probation
        ) {
            throw ValidationException::withMessages([
                'type' => ['Employees on probation cannot request annual leave. Please use unpaid leave instead.'],
            ]);
        }

        if (
            $leaveType->code !== LeaveTypeCode::Unpaid->value
            && $employee->employment_type !== EmploymentType::FullTime
        ) {
            throw ValidationException::withMessages([
                'type' => ['Only full-time employees can request this leave type. Please use unpaid leave instead.'],
            ]);
        }

        if (
            $leaveType->gender_restriction !== null
            && $leaveType->gender_restriction->value !== 'none'
            && $employee->gender instanceof EmployeeGender
            && $employee->gender->value !== $leaveType->gender_restriction->value
        ) {
            throw ValidationException::withMessages([
                'type' => ['The selected leave type is not available for this employee.'],
            ]);
        }

        if (
            $leaveType->gender_restriction !== null
            && $leaveType->gender_restriction->value !== 'none'
            && ! $employee->gender instanceof EmployeeGender
        ) {
            throw ValidationException::withMessages([
                'type' => ['The employee gender must be set before this leave type can be requested.'],
            ]);
        }

        if ($leaveType->min_service_days === null) {
            return;
        }

        if (
            $leaveType->code === LeaveTypeCode::Annual->value
            && (
                $employee->employment_type === EmploymentType::FullTime
                || $employee->confirmation_date instanceof CarbonInterface
            )
        ) {
            return;
        }

        $hireDate = $employee->hire_date;

        if (! $hireDate instanceof CarbonInterface) {
            throw ValidationException::withMessages([
                'employee' => ['The employee hire date is required to evaluate leave eligibility.'],
            ]);
        }

        if ($hireDate->copy()->startOfDay()->diffInDays($startDate, false) < $leaveType->min_service_days) {
            throw ValidationException::withMessages([
                'type' => ['The selected leave type is not yet available based on the employee service period.'],
            ]);
        }
    }

    private function assertLeaveNoticeRequirement(
        LeaveType $leaveType,
        CarbonInterface $startDate,
        float $requestedDays,
    ): void {
        if (! $this->leaveTypeUsesAdvanceNoticeRules($leaveType)) {
            return;
        }

        $minimumNoticeDays = null;

        if ($requestedDays > 4) {
            $minimumNoticeDays = 7;
        } elseif ($requestedDays > 2) {
            $minimumNoticeDays = 3;
        }

        if ($minimumNoticeDays === null) {
            return;
        }

        $noticeDays = today()->startOfDay()->diffInDays($startDate, false);

        if ($noticeDays < $minimumNoticeDays) {
            $leaveTypeLabel = $this->leaveTypeNoticeRuleSubject($leaveType);

            throw ValidationException::withMessages([
                'start_date' => ["{$leaveTypeLabel} requests for {$requestedDays} day(s) require at least {$minimumNoticeDays} days notice."],
            ]);
        }
    }

    private function assertLeavePolicyAllowsDuration(
        Employee $employee,
        LeaveType $leaveType,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        float $requestedDays,
        string $durationType = LeaveRequestDurationType::FullDay,
        ?int $ignoreLeaveRequestId = null,
    ): void {
        if ($leaveType->max_days_per_request !== null && $requestedDays > $leaveType->max_days_per_request) {
            throw ValidationException::withMessages([
                'end_date' => ['The selected leave duration exceeds the allowed days per request.'],
            ]);
        }

        foreach ($this->yearsCovered($startDate, $endDate) as $year) {
            $daysInYear = $this->calculateRequestedDaysForYear(
                $leaveType,
                $startDate,
                $endDate,
                $year,
                $durationType,
            );

            if ($daysInYear === 0) {
                continue;
            }

            $usage = $this->balanceSummaryForYear($employee, $leaveType, $year, $endDate, $ignoreLeaveRequestId);
            $usesFullEntitlementBalancePolicy = $this->usesFullEntitlementBalancePolicy($employee, $leaveType);
            $projectedYearDays = $usage['used_days'] + $daysInYear;

            if (! $usesFullEntitlementBalancePolicy) {
                $projectedYearDays += $usage['reserved_days'];
            }

            if (
                $leaveType->max_days_per_year !== null
                && (! $usesFullEntitlementBalancePolicy || ! $leaveType->requires_balance)
                && $projectedYearDays > $leaveType->max_days_per_year
            ) {
                throw ValidationException::withMessages([
                    'end_date' => ['The selected leave duration exceeds the yearly policy limit for this leave type.'],
                ]);
            }

            if ($leaveType->requires_balance && $daysInYear > $usage['available_days']) {
                throw ValidationException::withMessages([
                    'end_date' => ['The selected leave duration exceeds the available balance for this leave type.'],
                ]);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function yearsCovered(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $years = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $years[$date->year] = $date->year;
        }

        return array_values($years);
    }

    private function calculateRequestedDays(
        LeaveType $leaveType,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $durationType = LeaveRequestDurationType::FullDay,
    ): float {
        $leaveDays = $this->iterateLeaveDays($leaveType, $startDate, $endDate);

        if ($this->normalizeDurationType($durationType) === LeaveRequestDurationType::HalfDay) {
            return $leaveDays->isNotEmpty() ? 0.5 : 0.0;
        }

        return (float) $leaveDays->count();
    }

    private function calculateRequestedDaysForYear(
        LeaveType $leaveType,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        int $year,
        string $durationType = LeaveRequestDurationType::FullDay,
    ): float {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();
        $windowStart = $startDate->greaterThan($yearStart) ? $startDate->copy() : $yearStart;
        $windowEnd = $endDate->lessThan($yearEnd) ? $endDate->copy() : $yearEnd;

        if ($windowStart->gt($windowEnd)) {
            return 0.0;
        }

        return $this->calculateRequestedDays($leaveType, $windowStart, $windowEnd, $durationType);
    }

    /**
     * @return Collection<int, string>
     */
    private function iterateLeaveDays(
        LeaveType $leaveType,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
    ): Collection {
        $holidays = $this->holidayDatesBetween($startDate, $endDate);
        $days = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            if ($leaveType->auto_exclude_weekends && $date->isWeekend()) {
                continue;
            }

            if ($leaveType->auto_exclude_public_holidays && in_array($date->toDateString(), $holidays, true)) {
                continue;
            }

            $days[] = $date->toDateString();
        }

        return collect($days);
    }

    /**
     * @return array<int, string>
     */
    private function holidayDatesBetween(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        return $this->publicHolidayService->holidayDatesBetween($startDate, $endDate);
    }

    /**
     * @return array{year: int, entitlement_days: float, used_days: float, reserved_days: float, available_days: float}
     */
    private function balanceSummaryForYear(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        CarbonInterface $referenceDate,
        ?int $ignoreLeaveRequestId = null,
    ): array {
        $openRequests = LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('type', $leaveType->code)
            ->whereIn('status', [LeaveRequestStatus::Pending, LeaveRequestStatus::ManagerApproved])
            ->when(
                $ignoreLeaveRequestId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($ignoreLeaveRequestId)
            )
            ->get();

        $usedDays = $this->approvedLeaveDaysForYear($employee, $leaveType, $year);
        $reservedDays = $openRequests->sum(
            fn (LeaveRequest $leaveRequest): float => $this->calculateRequestedDaysForYear(
                $leaveType,
                Carbon::parse($leaveRequest->start_date),
                Carbon::parse($leaveRequest->end_date),
                $year,
                $this->leaveRequestDurationType($leaveRequest),
            )
        );
        $entitlementDays = $this->balanceEntitlementDaysForYear($employee, $leaveType, $year, $referenceDate);
        $usesFullEntitlementBalancePolicy = $this->usesFullEntitlementBalancePolicy($employee, $leaveType);

        return [
            'year' => $year,
            'entitlement_days' => $entitlementDays,
            'used_days' => round((float) $usedDays, 1),
            'reserved_days' => round((float) $reservedDays, 1),
            'available_days' => max(
                round(
                    $entitlementDays - (float) $usedDays - ($usesFullEntitlementBalancePolicy ? 0.0 : (float) $reservedDays),
                    1,
                ),
                0,
            ),
        ];
    }

    private function approvedLeaveDaysForYear(Employee $employee, LeaveType $leaveType, int $year): float
    {
        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('type', $leaveType->code)
            ->where('status', LeaveRequestStatus::HrApproved)
            ->get()
            ->sum(
                fn (LeaveRequest $leaveRequest): float => $this->calculateRequestedDaysForYear(
                    $leaveType,
                    Carbon::parse($leaveRequest->start_date),
                    Carbon::parse($leaveRequest->end_date),
                    $year,
                    $this->leaveRequestDurationType($leaveRequest),
                )
            );
    }

    private function balanceEntitlementDaysForYear(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        CarbonInterface $referenceDate,
    ): float {
        if ($this->usesFullEntitlementBalancePolicy($employee, $leaveType)) {
            return (float) ($this->leaveTypeTotalDaysForYear($employee, $leaveType, $year, $referenceDate) ?? 0);
        }

        return $this->entitlementDaysForYear($employee, $leaveType, $year, $referenceDate);
    }

    private function usesFullEntitlementBalancePolicy(Employee $employee, LeaveType $leaveType): bool
    {
        return $leaveType->requires_balance && $employee->employment_type === EmploymentType::FullTime;
    }

    private function leaveTypeTotalDaysForYear(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        CarbonInterface $referenceDate,
    ): ?float {
        if ($leaveType->max_days_per_year !== null) {
            return round((float) $leaveType->max_days_per_year, 1);
        }

        if ($leaveType->requires_balance) {
            if ($leaveType->code === LeaveTypeCode::Annual->value) {
                return $this->annualDisplayEntitlementDaysForYear($employee, $leaveType, $year, $referenceDate);
            }

            return $this->entitlementDaysForYear($employee, $leaveType, $year, $referenceDate);
        }

        return null;
    }

    private function annualDisplayEntitlementDaysForYear(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        CarbonInterface $referenceDate,
    ): float {
        $hireDate = $employee->hire_date;

        if (! $hireDate instanceof CarbonInterface) {
            return 0;
        }

        $eligibilityDate = $this->leaveTypeEligibilityDate($employee, $leaveType, $hireDate);
        $referenceCutoff = $referenceDate->copy()->endOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        if ($referenceCutoff->lt($eligibilityDate) || $yearEnd->lt($eligibilityDate)) {
            return 0;
        }

        $annualDays = (float) data_get($leaveType->metadata, 'law_defaults.total_days_per_year');

        if ($annualDays <= 0) {
            $annualDays = (float) data_get($leaveType->metadata, 'law_defaults.accrual_days_per_month', 0) * 12;
        }

        $bonusFrequency = (int) data_get($leaveType->metadata, 'law_defaults.seniority_bonus_day_every_service_years', 0);
        $bonusDays = (float) data_get($leaveType->metadata, 'law_defaults.seniority_bonus_days_added', 0);

        if ($bonusFrequency > 0 && $bonusDays > 0) {
            $serviceYears = $hireDate->diffInYears($referenceCutoff->lessThan($yearEnd) ? $referenceCutoff : $yearEnd);
            $annualDays += intdiv($serviceYears, $bonusFrequency) * $bonusDays;
        }

        return round($annualDays, 1);
    }

    private function entitlementDaysForYear(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        CarbonInterface $referenceDate,
    ): float {
        if (! $leaveType->requires_balance) {
            return 0;
        }

        $hireDate = $employee->hire_date;

        if (! $hireDate instanceof CarbonInterface) {
            return 0;
        }

        $referenceCutoff = $referenceDate->copy()->endOfDay();
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $eligibilityDate = $this->leaveTypeEligibilityDate($employee, $leaveType, $hireDate);

        if ($referenceCutoff->lt($eligibilityDate) || $yearEnd->lt($eligibilityDate)) {
            return 0;
        }

        $accrualStart = $eligibilityDate->greaterThan($yearStart) ? $eligibilityDate->copy() : $yearStart->copy();
        $accrualEnd = $referenceCutoff->lessThan($yearEnd) ? $referenceCutoff->copy() : $yearEnd->copy();

        if ($accrualStart->gt($accrualEnd)) {
            return 0;
        }

        $months = (($accrualEnd->year - $accrualStart->year) * 12) + ($accrualEnd->month - $accrualStart->month) + 1;
        $accrualDaysPerMonth = (float) data_get($leaveType->metadata, 'law_defaults.accrual_days_per_month', 0);
        $entitlement = $months * $accrualDaysPerMonth;
        $bonusFrequency = (int) data_get($leaveType->metadata, 'law_defaults.seniority_bonus_day_every_service_years', 0);
        $bonusDays = (float) data_get($leaveType->metadata, 'law_defaults.seniority_bonus_days_added', 0);

        if ($bonusFrequency > 0 && $bonusDays > 0) {
            $serviceYears = $hireDate->diffInYears($accrualEnd);
            $entitlement += intdiv($serviceYears, $bonusFrequency) * $bonusDays;
        }

        return round($entitlement, 1);
    }

    private function leaveTypeEligibilityDate(
        Employee $employee,
        LeaveType $leaveType,
        CarbonInterface $hireDate,
    ): CarbonInterface {
        $eligibilityDate = $hireDate->copy()->startOfDay()->addDays((int) ($leaveType->min_service_days ?? 0));

        if (
            $leaveType->code === LeaveTypeCode::Annual->value
            && (
                $employee->employment_type === EmploymentType::FullTime
                || $employee->confirmation_date instanceof CarbonInterface
            )
        ) {
            return $employee->confirmation_date instanceof CarbonInterface
                ? $employee->confirmation_date->copy()->startOfDay()
                : $hireDate->copy()->startOfDay();
        }

        return $eligibilityDate;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformLeaveRequest(LeaveRequest $leaveRequest, ?User $authenticatedUser = null): array
    {
        $leaveType = $leaveRequest->leaveType;
        $startDate = Carbon::parse($leaveRequest->start_date)->startOfDay();
        $endDate = Carbon::parse($leaveRequest->end_date)->startOfDay();
        $durationType = $this->leaveRequestDurationType($leaveRequest);
        $requestedDays = $leaveType instanceof LeaveType
            ? $this->calculateRequestedDays($leaveType, $startDate, $endDate, $durationType)
            : ($durationType === LeaveRequestDurationType::HalfDay ? 0.5 : (float) ($startDate->diffInDays($endDate) + 1));
        $managerApprovalStatus = $this->managerApprovalStatus($leaveRequest);
        $hrApprovalStatus = $this->hrApprovalStatus($leaveRequest);

        return [
            'id' => $leaveRequest->id,
            'employee_id' => $leaveRequest->employee_id,
            'type' => $leaveRequest->type,
            'leave_type_label' => $leaveType?->name,
            'reason' => $leaveRequest->reason,
            'duration_type' => $durationType,
            'half_day_session' => $leaveRequest->half_day_session,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'requested_days' => $requestedDays,
            'total_days' => $requestedDays,
            'status' => $leaveRequest->status,
            'status_label' => $this->leaveRequestStatusLabel($leaveRequest->status),
            'cancelable' => $this->canCancelLeaveRequest($leaveRequest, $authenticatedUser),
            'approval_stage' => $this->approvalStage($leaveRequest),
            'manager_approval_status' => $managerApprovalStatus,
            'hr_approval_status' => $hrApprovalStatus,
            'employee' => $this->transformEmployee($leaveRequest->employee),
            'leave_type' => $leaveType instanceof LeaveType ? [
                'code' => $leaveType->code,
                'name' => $leaveType->name,
                'is_paid' => $leaveType->is_paid,
                'requires_balance' => $leaveType->requires_balance,
            ] : null,
            'balances' => $leaveType instanceof LeaveType && $leaveRequest->employee instanceof Employee
                ? array_map(
                    fn (int $year): array => $this->balanceSummaryForYear(
                        $leaveRequest->employee,
                        $leaveType,
                        $year,
                        $endDate,
                    ),
                    $this->yearsCovered($startDate, $endDate),
                )
                : [],
            'manager_approved_by' => $this->transformApprover($leaveRequest->managerApprover),
            'manager_approved_at' => $leaveRequest->manager_approved_at?->toIso8601String(),
            'hr_approved_by' => $this->transformApprover($leaveRequest->hrApprover),
            'hr_approved_at' => $leaveRequest->hr_approved_at?->toIso8601String(),
            'approval_progress' => [
                'manager' => [
                    'status' => $managerApprovalStatus,
                    'approver' => $this->transformApprover($leaveRequest->managerApprover),
                    'acted_at' => $leaveRequest->manager_approved_at?->toIso8601String(),
                ],
                'hr' => [
                    'status' => $hrApprovalStatus,
                    'approver' => $this->transformApprover($leaveRequest->hrApprover),
                    'acted_at' => $leaveRequest->hr_approved_at?->toIso8601String(),
                ],
            ],
            'submitted_at' => $leaveRequest->created_at?->toIso8601String(),
            'created_at' => $leaveRequest->created_at?->toIso8601String(),
            'updated_at' => $leaveRequest->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{is_requestable: bool, request_restriction_reason: string|null}
     */
    private function leaveTypeRequestability(
        LeaveType $leaveType,
        Employee $employee,
        CarbonInterface $referenceDate,
    ): array {
        try {
            $this->assertLeaveTypeEligibleForEmployee($leaveType, $employee, $referenceDate);

            return [
                'is_requestable' => true,
                'request_restriction_reason' => null,
            ];
        } catch (ValidationException $exception) {
            $reason = collect($exception->errors())
                ->flatten()
                ->map(fn (mixed $message): string => (string) $message)
                ->first();

            return [
                'is_requestable' => false,
                'request_restriction_reason' => $reason,
            ];
        }
    }

    /**
     * @return array<int, array{leave_days_gt: int, minimum_notice_days: int}>
     */
    private function leaveTypeNoticeRules(LeaveType $leaveType): array
    {
        if (! $this->leaveTypeUsesAdvanceNoticeRules($leaveType)) {
            return [];
        }

        return [
            [
                'leave_days_gt' => 4,
                'minimum_notice_days' => 7,
            ],
            [
                'leave_days_gt' => 2,
                'minimum_notice_days' => 3,
            ],
        ];
    }

    private function leaveTypeNoticeRuleText(LeaveType $leaveType): ?string
    {
        if (! $this->leaveTypeUsesAdvanceNoticeRules($leaveType)) {
            return null;
        }

        return "{$this->leaveTypeNoticeRuleSubject($leaveType)} requests for more than 2 days require at least 3 days notice, and requests for more than 4 days require at least 7 days notice.";
    }

    private function leaveTypeUsesAdvanceNoticeRules(LeaveType $leaveType): bool
    {
        return in_array($leaveType->code, [
            LeaveTypeCode::Annual->value,
            LeaveTypeCode::Unpaid->value,
        ], true);
    }

    private function leaveTypeNoticeRuleSubject(LeaveType $leaveType): string
    {
        $name = $leaveType->name ?: 'This leave type';

        return preg_replace('/ Leave$/', ' leave', $name) ?? $name;
    }

    private function leaveTypeSupportsHalfDay(LeaveType $leaveType): bool
    {
        return in_array($leaveType->code, [
            LeaveTypeCode::Annual->value,
            LeaveTypeCode::Sick->value,
        ], true);
    }

    private function assertDurationTypeAllowedForLeaveType(LeaveType $leaveType, string $durationType): void
    {
        if (
            $this->normalizeDurationType($durationType) === LeaveRequestDurationType::HalfDay
            && ! $this->leaveTypeSupportsHalfDay($leaveType)
        ) {
            throw ValidationException::withMessages([
                'duration_type' => ['The selected leave type does not support half-day requests.'],
            ]);
        }
    }

    private function normalizeDurationType(?string $durationType): string
    {
        return in_array($durationType, LeaveRequestDurationType::all(), true)
            ? $durationType
            : LeaveRequestDurationType::FullDay;
    }

    private function leaveRequestDurationType(LeaveRequest $leaveRequest): string
    {
        /** @var string|null $durationType */
        $durationType = $leaveRequest->duration_type;

        return $this->normalizeDurationType($durationType);
    }

    private function canCancelLeaveRequest(LeaveRequest $leaveRequest, ?User $authenticatedUser = null): bool
    {
        if ($authenticatedUser === null) {
            return false;
        }

        $employee = $authenticatedUser->loadMissing('employee')->employee;

        if (! $employee instanceof Employee || $leaveRequest->employee_id !== $employee->id) {
            return false;
        }

        return in_array($leaveRequest->status, [
            LeaveRequestStatus::Pending,
            LeaveRequestStatus::ManagerApproved,
        ], true);
    }

    private function approvalStage(LeaveRequest $leaveRequest): string
    {
        return match ($leaveRequest->status) {
            LeaveRequestStatus::Pending => 'manager_review',
            LeaveRequestStatus::ManagerApproved => 'hr_review',
            LeaveRequestStatus::HrApproved => 'completed',
            LeaveRequestStatus::Rejected => 'rejected',
            LeaveRequestStatus::Cancelled => 'cancelled',
            default => 'unknown',
        };
    }

    private function managerApprovalStatus(LeaveRequest $leaveRequest): string
    {
        if ($leaveRequest->manager_approved_at !== null) {
            return 'approved';
        }

        return match ($leaveRequest->status) {
            LeaveRequestStatus::Rejected => 'rejected',
            LeaveRequestStatus::Cancelled => 'cancelled',
            default => 'pending',
        };
    }

    private function hrApprovalStatus(LeaveRequest $leaveRequest): string
    {
        if ($leaveRequest->hr_approved_at !== null) {
            return 'approved';
        }

        if ($leaveRequest->status === LeaveRequestStatus::Rejected && $leaveRequest->manager_approved_at !== null) {
            return 'rejected';
        }

        return match ($leaveRequest->status) {
            LeaveRequestStatus::Cancelled => 'cancelled',
            default => 'pending',
        };
    }

    private function leaveRequestStatusLabel(string $status): string
    {
        return match ($status) {
            LeaveRequestStatus::Pending => 'Pending',
            LeaveRequestStatus::ManagerApproved => 'Manager Approved',
            LeaveRequestStatus::HrApproved => 'HR Approved',
            LeaveRequestStatus::Rejected => 'Rejected',
            LeaveRequestStatus::Cancelled => 'Cancelled',
            default => str_replace('_', ' ', ucfirst($status)),
        };
    }

    /**
     * @return array{id: int, name: string, department: string|null, manager_id: int|null}|null
     */
    private function transformEmployee(?Employee $employee): ?array
    {
        if (! $employee instanceof Employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'department' => $employee->department?->name,
            'manager_id' => $employee->manager_id,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function transformApprover(?Employee $employee): ?array
    {
        if (! $employee instanceof Employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
        ];
    }
}
