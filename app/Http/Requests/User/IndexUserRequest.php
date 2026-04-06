<?php

namespace App\Http\Requests\User;

use App\EmployeeStatus;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'role_id' => ['nullable', 'integer', Rule::exists(Role::class, 'id')],
            'employee_id' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'employee_status' => ['nullable', 'string', Rule::in(array_column(EmployeeStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
