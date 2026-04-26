<?php

namespace App\Http\Requests\Payroll;

use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MarkPayrollRunPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::PayrollRunMarkPaid->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
