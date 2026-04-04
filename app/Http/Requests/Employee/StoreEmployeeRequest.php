<?php

namespace App\Http\Requests\Employee;

use App\EmergencyContactRelationship;
use App\EmployeeGender;
use App\EmployeeIdType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
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
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'position_id' => [
                'required',
                'integer',
                Rule::exists('positions', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('employees', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::enum(EmployeeGender::class)],
            'personal_phone' => ['nullable', 'string', 'max:30'],
            'personal_email' => ['nullable', 'string', 'email', 'max:255'],
            'current_address' => ['nullable', 'string'],
            'permanent_address' => ['nullable', 'string'],
            'id_type' => ['nullable', Rule::enum(EmployeeIdType::class)],
            'id_number' => ['nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['nullable', Rule::enum(EmergencyContactRelationship::class)],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'hire_date' => ['required', 'date'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'terminated'])],
        ];
    }
}
