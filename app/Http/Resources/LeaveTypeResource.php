<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
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
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'is_paid' => $this->resource->is_paid,
            'requires_balance' => $this->resource->requires_balance,
            'requires_attachment' => $this->resource->requires_attachment,
            'requires_medical_certificate' => $this->resource->requires_medical_certificate,
            'auto_exclude_public_holidays' => $this->resource->auto_exclude_public_holidays,
            'auto_exclude_weekends' => $this->resource->auto_exclude_weekends,
            'gender_restriction' => $this->resource->gender_restriction?->value,
            'min_service_days' => $this->resource->min_service_days,
            'max_days_per_request' => $this->resource->max_days_per_request,
            'max_days_per_year' => $this->resource->max_days_per_year,
        ];
    }
}
