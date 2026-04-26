<?php

namespace App\Http\Requests\Employee;

use App\EmergencyContactRelationship;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::EmployeeManage->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'relationship' => ['required', Rule::enum(EmergencyContactRelationship::class)],
            'phone' => ['required', 'regex:/^0\d{8,9}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone field must start with 0 and contain 9 to 10 digits.',
        ];
    }
}
