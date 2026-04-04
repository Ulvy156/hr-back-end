<?php

namespace App\Http\Requests\Attendance;

use App\Services\Attendance\AttendanceCorrectionRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class CorrectionRequestReviewRequest extends FormRequest
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
                AttendanceCorrectionRequestStatus::Approved,
                AttendanceCorrectionRequestStatus::Rejected,
            ])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
