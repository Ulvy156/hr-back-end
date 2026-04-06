<?php

namespace App\Http\Requests\Employee;

use App\EmployeeEducationLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeEducationRequest extends FormRequest
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
            'institution_name' => ['required', 'string', 'max:255'],
            'education_level' => ['nullable', Rule::enum(EmployeeEducationLevel::class)],
            'degree' => ['nullable', 'string', 'max:150'],
            'field_of_study' => ['nullable', 'string', 'max:150'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'graduation_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'grade' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ];
    }
}
