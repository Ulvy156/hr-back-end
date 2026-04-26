<?php

namespace App\Http\Requests\Employee;

use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeePositionRequest extends FormRequest
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
            'position_id' => [
                'required',
                'integer',
                Rule::exists('positions', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
