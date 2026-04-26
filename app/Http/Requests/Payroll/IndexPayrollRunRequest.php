<?php

namespace App\Http\Requests\Payroll;

use App\Models\PayrollRun;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::PayrollRunView->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    PayrollRun::STATUS_DRAFT,
                    PayrollRun::STATUS_APPROVED,
                    PayrollRun::STATUS_PAID,
                    PayrollRun::STATUS_CANCELLED,
                ]),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
