<?php

namespace App\Http\Requests\Employee;

use App\EmployeeStatus;
use App\EmploymentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(EmployeeStatus::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->whereNull('deleted_at'))],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->whereNull('deleted_at'))],
            'current_position_id' => ['sometimes', 'nullable', 'integer', Rule::exists('positions', 'id')->where(fn ($query) => $query->whereNull('deleted_at'))],
            'manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at'))],
            'employment_type' => ['sometimes', 'nullable', Rule::enum(EmploymentType::class)],
            'hire_date_from' => ['sometimes', 'nullable', 'date'],
            'hire_date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:hire_date_from'],
            'sort_by' => ['sometimes', 'nullable', 'string', Rule::in(['id', 'employee_code', 'first_name', 'last_name', 'email', 'status', 'hire_date', 'created_at', 'updated_at'])],
            'sort_direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'include' => ['sometimes'],
        ];
    }
}
