<?php

namespace App\Http\Requests\Payroll;

use App\Models\Employee;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeSalaryRequest extends FormRequest
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
            'employee_id' => [
                'required',
                'integer',
                Rule::exists(Employee::class, 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                ),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'effective_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }
}
