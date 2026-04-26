<?php

namespace App\Http\Requests\Employee;

use App\EmployeeAddressType;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeAddressRequest extends FormRequest
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
            'address_type' => ['required', Rule::enum(EmployeeAddressType::class)],
            'province_id' => ['nullable', 'integer', Rule::exists('provinces', 'id')],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'commune_id' => ['nullable', 'integer', Rule::exists('communes', 'id')],
            'village_id' => ['nullable', 'integer', Rule::exists('villages', 'id')],
            'address_line' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:150'],
            'house_no' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
