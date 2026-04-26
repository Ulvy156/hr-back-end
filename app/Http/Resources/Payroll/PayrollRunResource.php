<?php

namespace App\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
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
            'payroll_month' => $this->resource->payroll_month?->toDateString(),
            'status' => $this->resource->status,
            'company_working_days' => $this->resource->company_working_days,
            'monthly_working_hours' => $this->resource->monthly_working_hours,
            'employee_count' => $this->resource->employee_count,
            'total_base_salary' => $this->resource->total_base_salary,
            'total_prorated_base_salary' => $this->resource->total_prorated_base_salary,
            'total_overtime_pay' => $this->resource->total_overtime_pay,
            'total_unpaid_leave_deduction' => $this->resource->total_unpaid_leave_deduction,
            'total_tax_amount' => $this->resource->total_tax_amount,
            'total_net_salary' => $this->resource->total_net_salary,
            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => PayrollRunItemResource::collection($this->resource->items)->resolve($request),
            ),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
