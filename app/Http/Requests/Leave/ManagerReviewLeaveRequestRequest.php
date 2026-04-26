<?php

namespace App\Http\Requests\Leave;

use App\PermissionName;
use App\Services\Leave\LeaveRequestStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManagerReviewLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::LeaveApproveManager->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                LeaveRequestStatus::ManagerApproved,
                LeaveRequestStatus::Rejected,
            ])],
        ];
    }
}
