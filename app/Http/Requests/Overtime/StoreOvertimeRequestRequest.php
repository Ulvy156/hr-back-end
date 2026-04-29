<?php

namespace App\Http\Requests\Overtime;

use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::OvertimeRequestCreate->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['start_time', 'end_time'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);

                if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
                    $value .= ':00';
                }

                $this->merge([$field => $value]);
            }
        }

        if ($this->has('reason')) {
            $reason = $this->input('reason');

            $this->merge([
                'reason' => is_string($reason) ? trim($reason) : $reason,
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'overtime_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s', 'after:start_time'],
            'reason' => ['required', 'string', 'min:3'],
            'employee_id' => ['prohibited'],
            'status' => ['prohibited'],
            'approval_stage' => ['prohibited'],
            'manager_approved_by' => ['prohibited'],
            'manager_approved_at' => ['prohibited'],
            'hr_approved_by' => ['prohibited'],
            'hr_approved_at' => ['prohibited'],
            'rejected_by' => ['prohibited'],
            'rejected_at' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'minutes' => ['prohibited'],
            'overtime_type' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Reason is required.',
            'reason.min' => 'Please provide a reason for your overtime request.',
        ];
    }
}
