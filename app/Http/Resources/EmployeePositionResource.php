<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePositionResource extends JsonResource
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
            'position_id' => $this->resource->position_id,
            'position' => $this->resource->relationLoaded('position') && $this->resource->position !== null ? [
                'id' => $this->resource->position->id,
                'title' => $this->resource->position->title,
            ] : null,
            'base_salary' => $this->resource->base_salary,
            'start_date' => $this->resource->start_date?->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),
            'is_current' => $this->resource->end_date === null,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
