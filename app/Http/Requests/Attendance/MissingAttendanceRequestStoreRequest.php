<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MissingAttendanceRequestStoreRequest extends FormRequest
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
            'request_date' => ['required', 'date_format:Y-m-d'],
            'requested_check_in_time' => ['nullable', 'string', 'date_format:H:i', 'required_without:requested_check_out_time'],
            'requested_check_out_time' => ['nullable', 'string', 'date_format:H:i', 'required_without:requested_check_in_time'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
