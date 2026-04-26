<?php

namespace App\Http\Requests\Attendance;

use App\PermissionName;
use App\Services\Attendance\AttendanceCorrectionRequestStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectionRequestReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::AttendanceManage->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                AttendanceCorrectionRequestStatus::Approved,
                AttendanceCorrectionRequestStatus::Rejected,
            ])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
