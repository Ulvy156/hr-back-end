<?php

namespace App\Http\Requests\Payroll;

use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::PayrollSalaryManage->value) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['prohibited'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'effective_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }
}
