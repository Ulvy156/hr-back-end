<?php

namespace App\Http\Requests\Overtime;

use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::OvertimeApproveManager->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rejection_reason')) {
            $reason = $this->input('rejection_reason');

            $this->merge([
                'rejection_reason' => is_string($reason) ? trim($reason) : $reason,
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:3'],
        ];
    }
}
