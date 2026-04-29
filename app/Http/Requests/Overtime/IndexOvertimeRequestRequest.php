<?php

namespace App\Http\Requests\Overtime;

use App\PermissionName;
use App\Services\Overtime\OvertimeApprovalStage;
use App\Services\Overtime\OvertimeRequestStatus;
use App\Services\Overtime\OvertimeType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->canAny([
            PermissionName::OvertimeRequestViewAny->value,
            PermissionName::OvertimeRequestViewAssigned->value,
            PermissionName::OvertimeRequestViewSelf->value,
        ]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'string', Rule::in(OvertimeRequestStatus::all())],
            'approval_stage' => ['nullable', 'string', Rule::in(OvertimeApprovalStage::all())],
            'overtime_type' => ['nullable', 'string', Rule::in(OvertimeType::all())],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
