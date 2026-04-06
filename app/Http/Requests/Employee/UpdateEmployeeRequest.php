<?php

namespace App\Http\Requests\Employee;

use App\EmergencyContactRelationship;
use App\EmployeeAddressType;
use App\EmployeeEducationLevel;
use App\EmployeeGender;
use App\EmployeeIdType;
use App\EmployeeStatus;
use App\EmploymentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('position_id') && ! $this->has('current_position_id')) {
            $this->merge([
                'current_position_id' => $this->input('position_id'),
            ]);
        }
    }

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
        $employeeId = (int) $this->route('id');

        return [
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($employeeId),
            ],
            'employee_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
                Rule::unique('employees', 'employee_code')
                    ->where(fn ($query) => $query->whereNull('deleted_at'))
                    ->ignore($employeeId),
            ],
            'department_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'branch_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'current_position_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('positions', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'position_id' => ['sometimes', 'integer'],
            'shift_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('shifts', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'manager_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
                function (string $attribute, mixed $value, \Closure $fail) use ($employeeId): void {
                    if ($value !== null && (int) $value === $employeeId) {
                        $fail('An employee cannot be their own manager.');
                    }
                },
            ],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('employees', 'email')->where(fn ($query) => $query->whereNull('deleted_at'))->ignore($employeeId)],
            'phone' => ['sometimes', 'required', ...$this->phoneFormatRules()],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', Rule::enum(EmployeeGender::class)],
            'personal_phone' => ['sometimes', 'nullable', ...$this->phoneFormatRules()],
            'personal_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'addresses' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $primaryAddresses = collect($value)
                        ->filter(fn (mixed $address): bool => is_array($address) && (bool) ($address['is_primary'] ?? false))
                        ->count();

                    if ($primaryAddresses > 1) {
                        $fail('Only one employee address may be primary.');
                    }
                },
            ],
            'addresses.*.address_type' => ['required_with:addresses', Rule::enum(EmployeeAddressType::class)],
            'addresses.*.province_id' => ['nullable', 'integer', Rule::exists('provinces', 'id')],
            'addresses.*.district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'addresses.*.commune_id' => ['nullable', 'integer', Rule::exists('communes', 'id')],
            'addresses.*.village_id' => ['nullable', 'integer', Rule::exists('villages', 'id')],
            'addresses.*.address_line' => ['nullable', 'string', 'max:255'],
            'addresses.*.street' => ['nullable', 'string', 'max:150'],
            'addresses.*.house_no' => ['nullable', 'string', 'max:50'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:30'],
            'addresses.*.note' => ['nullable', 'string', 'max:1000'],
            'addresses.*.is_primary' => ['nullable', 'boolean'],
            'id_type' => ['sometimes', 'nullable', Rule::enum(EmployeeIdType::class)],
            'id_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', Rule::enum(EmergencyContactRelationship::class)],
            'emergency_contact_phone' => ['sometimes', 'nullable', ...$this->phoneFormatRules()],
            'emergency_contacts' => ['sometimes', 'array'],
            'emergency_contacts.*.name' => ['required_with:emergency_contacts', 'string', 'max:150'],
            'emergency_contacts.*.relationship' => ['required_with:emergency_contacts', Rule::enum(EmergencyContactRelationship::class)],
            'emergency_contacts.*.phone' => ['required_with:emergency_contacts', ...$this->phoneFormatRules()],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.is_primary' => ['nullable', 'boolean'],
            'educations' => ['sometimes', 'array'],
            'educations.*.institution_name' => ['required_with:educations', 'string', 'max:255'],
            'educations.*.education_level' => ['nullable', Rule::enum(EmployeeEducationLevel::class)],
            'educations.*.degree' => ['nullable', 'string', 'max:150'],
            'educations.*.field_of_study' => ['nullable', 'string', 'max:150'],
            'educations.*.start_date' => ['nullable', 'date'],
            'educations.*.end_date' => ['nullable', 'date'],
            'educations.*.graduation_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'educations.*.grade' => ['nullable', 'string', 'max:50'],
            'educations.*.description' => ['nullable', 'string'],
            'employee_positions' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $activePositions = collect($value)
                        ->filter(fn (mixed $position): bool => is_array($position) && empty($position['end_date']))
                        ->count();

                    if ($activePositions > 1) {
                        $fail('Only one employee position may be current.');
                    }
                },
            ],
            'employee_positions.*.position_id' => [
                'required_with:employee_positions',
                'integer',
                Rule::exists('positions', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'employee_positions.*.base_salary' => ['required_with:employee_positions', 'numeric', 'min:0'],
            'employee_positions.*.start_date' => ['required_with:employee_positions', 'date'],
            'employee_positions.*.end_date' => ['nullable', 'date'],
            'profile_photo' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'profile_photo_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'hire_date' => ['sometimes', 'required', 'date'],
            'employment_type' => ['sometimes', 'nullable', Rule::enum(EmploymentType::class)],
            'confirmation_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:hire_date'],
            'termination_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:hire_date'],
            'last_working_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:hire_date'],
            'status' => ['sometimes', 'required', Rule::enum(EmployeeStatus::class)],
            'include' => ['sometimes'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone field must start with 0 and contain 9 to 10 digits.',
            'personal_phone.regex' => 'The personal phone field must start with 0 and contain 9 to 10 digits.',
            'emergency_contact_phone.regex' => 'The emergency contact phone field must start with 0 and contain 9 to 10 digits.',
            'emergency_contacts.*.phone.regex' => 'Each emergency contact phone must start with 0 and contain 9 to 10 digits.',
        ];
    }

    /**
     * @return list<string>
     */
    private function phoneFormatRules(): array
    {
        return ['regex:/^0\d{8,9}$/'];
    }
}
