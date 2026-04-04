<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class CorrectionRequestStoreRequest extends FormRequest
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
            'attendance_id' => ['required', 'integer', 'exists:attendances,id'],
            'requested_check_in_time' => ['nullable', 'date', 'required_without:requested_check_out_time'],
            'requested_check_out_time' => ['nullable', 'date', 'required_without:requested_check_in_time', 'after_or_equal:requested_check_in_time'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
