<?php

namespace Database\Seeders;

use App\LeaveTypeCode;
use App\LeaveTypeGenderRestriction;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect($this->defaultLeaveTypes())
            ->each(function (array $attributes): void {
                LeaveType::query()->updateOrCreate(
                    ['code' => $attributes['code']],
                    $attributes,
                );
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultLeaveTypes(): array
    {
        return [
            [
                'code' => LeaveTypeCode::Annual->value,
                'name' => 'Annual Leave',
                'description' => 'Paid annual leave. Cambodia labor law default accrual is 1.5 working days per month of continuous service, with seniority increase over time. Right to use annual leave is acquired after 1 year of service.',
                'is_paid' => true,
                'requires_balance' => true,
                'requires_attachment' => false,
                'requires_medical_certificate' => false,
                'auto_exclude_public_holidays' => true,
                'auto_exclude_weekends' => true,
                'gender_restriction' => LeaveTypeGenderRestriction::None->value,
                'min_service_days' => 365,
                'max_days_per_request' => null,
                'max_days_per_year' => null,
                'is_active' => true,
                'sort_order' => 10,
                'metadata' => [
                    'law_defaults' => [
                        'accrual_days_per_month' => 1.5,
                        'seniority_bonus_day_every_service_years' => 3,
                        'seniority_bonus_days_added' => 1,
                        'usable_after_service_days' => 365,
                        'exclude_paid_public_holidays_from_deduction' => true,
                        'exclude_sick_leave_from_annual_leave_deduction' => true,
                    ],
                ],
            ],
            [
                'code' => LeaveTypeCode::Sick->value,
                'name' => 'Sick Leave',
                'description' => 'Sick leave. Support medical-certificate-based leave. Do not hard-code a fixed annual sick balance unless company policy requires it later.',
                'is_paid' => false,
                'requires_balance' => false,
                'requires_attachment' => true,
                'requires_medical_certificate' => true,
                'auto_exclude_public_holidays' => true,
                'auto_exclude_weekends' => false,
                'gender_restriction' => LeaveTypeGenderRestriction::None->value,
                'min_service_days' => null,
                'max_days_per_request' => null,
                'max_days_per_year' => null,
                'is_active' => true,
                'sort_order' => 20,
                'metadata' => [
                    'policy_notes' => [
                        'paid_default_is_company_configurable' => true,
                    ],
                ],
            ],
            [
                'code' => LeaveTypeCode::Maternity->value,
                'name' => 'Maternity Leave',
                'description' => 'Maternity leave for 90 days. Keep backend ready for payroll-related treatment later.',
                'is_paid' => true,
                'requires_balance' => false,
                'requires_attachment' => true,
                'requires_medical_certificate' => false,
                'auto_exclude_public_holidays' => false,
                'auto_exclude_weekends' => false,
                'gender_restriction' => LeaveTypeGenderRestriction::Female->value,
                'min_service_days' => null,
                'max_days_per_request' => 90,
                'max_days_per_year' => null,
                'is_active' => true,
                'sort_order' => 30,
                'metadata' => [
                    'law_defaults' => [
                        'duration_days' => 90,
                    ],
                ],
            ],
            [
                'code' => LeaveTypeCode::Special->value,
                'name' => 'Special Leave',
                'description' => 'Special leave for immediate-family or personal events. Keep configurable because company policy may vary.',
                'is_paid' => false,
                'requires_balance' => false,
                'requires_attachment' => false,
                'requires_medical_certificate' => false,
                'auto_exclude_public_holidays' => true,
                'auto_exclude_weekends' => false,
                'gender_restriction' => LeaveTypeGenderRestriction::None->value,
                'min_service_days' => null,
                'max_days_per_request' => null,
                'max_days_per_year' => null,
                'is_active' => true,
                'sort_order' => 40,
                'metadata' => [
                    'policy_notes' => [
                        'paid_default_is_company_configurable' => true,
                    ],
                ],
            ],
            [
                'code' => LeaveTypeCode::Unpaid->value,
                'name' => 'Unpaid Leave',
                'description' => 'Unpaid leave for cases where paid leave does not apply or balance is insufficient.',
                'is_paid' => false,
                'requires_balance' => false,
                'requires_attachment' => false,
                'requires_medical_certificate' => false,
                'auto_exclude_public_holidays' => true,
                'auto_exclude_weekends' => false,
                'gender_restriction' => LeaveTypeGenderRestriction::None->value,
                'min_service_days' => null,
                'max_days_per_request' => null,
                'max_days_per_year' => null,
                'is_active' => true,
                'sort_order' => 50,
                'metadata' => null,
            ],
        ];
    }
}
