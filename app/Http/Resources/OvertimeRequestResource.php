<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeRequestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'employee_id' => $this->resource->employee_id,
            'overtime_date' => $this->resource->overtime_date?->toDateString(),
            'start_time' => $this->resource->start_time,
            'end_time' => $this->resource->end_time,
            'reason' => $this->resource->reason,
            'status' => $this->resource->status,
            'status_label' => OvertimeRequestStatus::label((string) $this->resource->status),
            'approval_stage' => $this->resource->approval_stage,
            'approval_stage_label' => OvertimeApprovalStage::label((string) $this->resource->approval_stage),
            'minutes' => (int) $this->resource->minutes,
            'hours' => round(((int) $this->resource->minutes) / 60, 2),
            'overtime_type' => $this->resource->overtime_type,
            'overtime_type_label' => OvertimeType::label((string) $this->resource->overtime_type),
            'cancelable' => $this->isCancelable($request),
            'employee' => $this->transformEmployee($this->resource->employee),
            'manager_approved_by' => $this->transformApprover($this->resource->managerApprover),
            'manager_approved_at' => $this->resource->manager_approved_at?->toIso8601String(),
            'rejected_by' => $this->transformApprover($this->resource->rejector),
            'rejected_at' => $this->resource->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->resource->rejection_reason,
            'submitted_at' => $this->resource->created_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    private function isCancelable(Request $request): bool
    {
        $employee = ($request->user('api') ?? $request->user())?->loadMissing('employee')->employee;

        if (! $employee instanceof Employee || $this->resource->employee_id !== $employee->id) {
            return false;
        }

        return (string) $this->resource->status === OvertimeRequestStatus::Pending;
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
