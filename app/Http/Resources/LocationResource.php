<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
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
            'source_id' => $this->resource->source_id,
            'code' => $this->resource->code,
            'name_kh' => $this->resource->name_kh,
            'name_en' => $this->resource->name_en,
            'type' => $this->when(isset($this->resource->type), $this->resource->type),
            'province_id' => $this->when(isset($this->resource->province_id), $this->resource->province_id),
            'district_id' => $this->when(isset($this->resource->district_id), $this->resource->district_id),
            'commune_id' => $this->when(isset($this->resource->commune_id), $this->resource->commune_id),
            'is_not_active' => $this->when(isset($this->resource->is_not_active), (bool) $this->resource->is_not_active),
        ];
    }
}
