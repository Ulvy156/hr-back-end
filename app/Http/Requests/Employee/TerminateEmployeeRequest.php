<?php

namespace App\Http\Requests\Employee;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TerminateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'termination_date' => ['sometimes', 'nullable', 'date'],
            'last_working_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:termination_date'],
        ];
    }
}
