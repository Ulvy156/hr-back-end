<?php

namespace App\Http\Requests\Attendance;

use App\PermissionName;
use App\Services\Attendance\AttendanceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceCorrectRequest extends FormRequest
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
            'check_in_time' => ['sometimes', 'nullable', 'date'],
            'check_out_time' => ['sometimes', 'nullable', 'date', 'after_or_equal:check_in_time'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(AttendanceStatus::persisted())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'correction_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
