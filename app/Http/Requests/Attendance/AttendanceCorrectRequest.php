<?php

namespace App\Http\Requests\Attendance;

use App\Services\Attendance\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class AttendanceCorrectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
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
