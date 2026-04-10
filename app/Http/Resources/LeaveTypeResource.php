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
            'label' => $this->resource->name,
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
            'supports_half_day' => (bool) ($this->resource->supports_half_day ?? false),
            'supported_half_day_sessions' => $this->resource->supported_half_day_sessions ?? [],
            'notice_rules' => $this->resource->notice_rules ?? [],
            'notice_rule_text' => $this->resource->notice_rule_text,
            'is_requestable' => (bool) ($this->resource->is_requestable ?? true),
            'request_restriction_reason' => $this->resource->request_restriction_reason,
            'balance_snapshot' => $this->resource->balance_snapshot,
        ];
    }
}
