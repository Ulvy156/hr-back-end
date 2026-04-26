<?php

namespace App\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnPayslipResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'payroll_run_id' => $this->resource->payroll_run_id,
            'payroll_month' => $this->resource->payrollRun?->payroll_month?->toDateString(),
            'payroll_status' => $this->resource->payrollRun?->status,
            'salary_source' => $this->resource->salary_source,
            'base_salary' => $this->resource->base_salary,
            'prorated_base_salary' => $this->resource->prorated_base_salary,
            'hourly_rate' => $this->resource->hourly_rate,
            'daily_rate' => $this->resource->daily_rate,
            'eligible_working_days' => $this->resource->eligible_working_days,
            'company_working_days' => $this->resource->company_working_days,
            'monthly_working_hours' => $this->resource->monthly_working_hours,
            'overtime_normal_hours' => $this->resource->overtime_normal_hours,
            'overtime_weekend_hours' => $this->resource->overtime_weekend_hours,
            'overtime_holiday_hours' => $this->resource->overtime_holiday_hours,
            'overtime_pay' => $this->resource->overtime_pay,
            'unpaid_leave_units' => $this->resource->unpaid_leave_units,
            'unpaid_leave_deduction' => $this->resource->unpaid_leave_deduction,
            'tax_amount' => $this->resource->tax_amount,
            'raw_net_salary' => $this->resource->raw_net_salary,
            'net_salary' => $this->resource->net_salary,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
