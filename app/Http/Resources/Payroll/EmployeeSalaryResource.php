<?php

namespace App\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeSalaryResource extends JsonResource
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
            'employee_id' => $this->resource->employee_id,
            'employee' => $this->when(
                $this->resource->relationLoaded('employee') && $this->resource->employee !== null,
                fn (): array => [
                    'id' => $this->resource->employee->id,
                    'employee_code' => $this->resource->employee->employee_code,
                    'full_name' => $this->resource->employee->full_name,
                ],
            ),
            'amount' => $this->resource->amount,
            'effective_date' => $this->resource->effective_date?->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),
            'status' => $this->resource->end_date === null ? 'current' : 'ended',
            'is_current' => $this->resource->effective_date !== null
                && $this->resource->effective_date->copy()->startOfDay()->lte(today()->startOfDay())
                && ($this->resource->end_date === null || $this->resource->end_date->copy()->startOfDay()->gte(today()->startOfDay())),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
