<?php

namespace App\Http\Requests\Attendance;

use App\PermissionName;
use App\Services\Attendance\AttendanceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceManualStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::AttendanceManage->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'attendance_date' => ['required', 'date'],
            'check_in_time' => ['nullable', 'date'],
            'check_out_time' => ['nullable', 'date', 'after_or_equal:check_in_time'],
            'status' => ['nullable', 'string', Rule::in(AttendanceStatus::persisted())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'correction_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
