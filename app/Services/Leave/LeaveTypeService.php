<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LeaveTypeService
{
    public function __construct(private LeaveRequestService $leaveRequestService) {}

    public function listActive(?User $authenticatedUser = null): Collection
    {
        $employee = $authenticatedUser?->loadMissing('employee')->employee;
        $referenceDate = today()->startOfDay();

        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (LeaveType $leaveType) use ($employee, $referenceDate): LeaveType {
                $metadata = $this->leaveRequestService->leaveTypeUiMetadata(
                    $leaveType,
                    $employee instanceof Employee ? $employee : null,
                    $referenceDate,
                );

                foreach ($metadata as $key => $value) {
                    $leaveType->setAttribute($key, $value);
                }

                return $leaveType;
            });
    }

    public function currentBalances(?User $authenticatedUser = null): Collection
    {
        $employee = $this->leaveRequestService->currentEmployeeProfile($authenticatedUser);
        $referenceDate = today()->startOfDay();

        return LeaveType::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (LeaveType $leaveType) use ($employee, $referenceDate): LeaveType {
                $balanceSnapshot = $this->leaveRequestService->currentLeaveBalanceSnapshot(
                    $employee,
                    $leaveType,
                    $referenceDate,
                );

                foreach ($balanceSnapshot as $key => $value) {
                    $leaveType->setAttribute($key, $value);
                }

                return $leaveType;
            });
    }
}
