<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
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
            'leave_type' => [
                'code' => $this->resource->code,
                'name' => $this->resource->name,
                'label' => $this->resource->name,
                'is_paid' => $this->resource->is_paid,
                'requires_balance' => $this->resource->requires_balance,
            ],
            'year' => $this->resource->year,
            'total_days' => $this->resource->total_days,
            'used_days' => $this->resource->used_days,
            'remaining_days' => $this->resource->remaining_days,
        ];
    }
}
