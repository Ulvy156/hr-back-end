<?php

namespace App\Http\Requests\Payroll;

use App\Models\Employee;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEmployeeSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::PayrollSalaryView->value) ?? false;
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
                'nullable',
                'integer',
                Rule::exists(Employee::class, 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                ),
            ],
            'status' => ['nullable', Rule::in(['current', 'ended', 'all'])],
            'effective_date' => ['nullable', 'date'],
            'effective_on' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
