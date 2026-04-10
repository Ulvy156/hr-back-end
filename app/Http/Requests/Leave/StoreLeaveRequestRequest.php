<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveRequestDurationType;
use App\Services\Leave\LeaveRequestHalfDaySession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    protected function prepareForValidation(): void
    {
        $durationType = $this->input('duration_type', LeaveRequestDurationType::FullDay);

        if (is_string($durationType)) {
            $durationType = trim($durationType);
        }

        $this->merge([
            'duration_type' => $durationType,
        ]);

        if ($this->has('reason')) {
            $this->merge([
                'reason' => is_string($this->input('reason'))
                    ? trim($this->input('reason'))
                    : $this->input('reason'),
            ]);
        }

        if ($this->has('half_day_session')) {
            $halfDaySession = $this->input('half_day_session');

            $this->merge([
                'half_day_session' => is_string($halfDaySession)
                    ? strtoupper(trim($halfDaySession))
                    : $halfDaySession,
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                Rule::exists('leave_types', 'code')->where(
                    fn ($query) => $query->where('is_active', true)
                ),
            ],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
                Rule::when(
                    $this->input('duration_type', LeaveRequestDurationType::FullDay) === LeaveRequestDurationType::HalfDay,
                    ['same:start_date'],
                ),
            ],
            'reason' => ['required', 'string', 'min:3'],
            'duration_type' => ['required', 'string', Rule::in(LeaveRequestDurationType::all())],
            'half_day_session' => [
                Rule::requiredIf(
                    $this->input('duration_type', LeaveRequestDurationType::FullDay) === LeaveRequestDurationType::HalfDay
                ),
                'nullable',
                'string',
                Rule::in(LeaveRequestHalfDaySession::all()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Reason is required.',
            'reason.min' => 'Please provide a reason for your leave request.',
            'half_day_session.required' => 'Please select AM or PM for a half-day leave request.',
            'end_date.same' => 'Half-day leave must start and end on the same date.',
        ];
    }
}
