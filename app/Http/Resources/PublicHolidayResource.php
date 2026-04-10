<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicHolidayResource extends JsonResource
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
            'name' => $this->resource->name,
            'holiday_date' => $this->resource->holiday_date,
            'year' => $this->resource->year,
            'country_code' => $this->resource->country_code,
            'is_paid' => $this->resource->is_paid,
            'source' => $this->resource->source,
            'metadata' => $this->resource->metadata,
        ];
    }
}
