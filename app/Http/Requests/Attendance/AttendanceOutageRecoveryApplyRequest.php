<?php

namespace App\Http\Requests\Attendance;

use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceOutageRecoveryApplyRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => [
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'check_in_time' => ['nullable', 'date'],
            'check_out_time' => ['nullable', 'date', 'after_or_equal:check_in_time'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
