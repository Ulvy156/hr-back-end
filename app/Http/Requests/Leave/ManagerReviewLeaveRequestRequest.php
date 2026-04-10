<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveRequestStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManagerReviewLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
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
