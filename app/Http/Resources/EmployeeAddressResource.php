<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAddressResource extends JsonResource
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
            'address_type' => $this->resource->address_type?->value,
            'province_id' => $this->resource->province_id,
            'district_id' => $this->resource->district_id,
            'commune_id' => $this->resource->commune_id,
            'village_id' => $this->resource->village_id,
            'address_line' => $this->resource->address_line,
            'street' => $this->resource->street,
            'house_no' => $this->resource->house_no,
            'postal_code' => $this->resource->postal_code,
            'note' => $this->resource->note,
            'is_primary' => (bool) $this->resource->is_primary,
            'province' => $this->when(
                $this->resource->relationLoaded('province') && $this->resource->province !== null,
                fn (): array => [
                    'id' => $this->resource->province->id,
                    'code' => $this->resource->province->code,
                    'name_kh' => $this->resource->province->name_kh,
                    'name_en' => $this->resource->province->name_en,
                ],
            ),
            'district' => $this->when(
                $this->resource->relationLoaded('district') && $this->resource->district !== null,
                fn (): array => [
                    'id' => $this->resource->district->id,
                    'code' => $this->resource->district->code,
                    'name_kh' => $this->resource->district->name_kh,
                    'name_en' => $this->resource->district->name_en,
                    'type' => $this->resource->district->type,
                ],
            ),
            'commune' => $this->when(
                $this->resource->relationLoaded('commune') && $this->resource->commune !== null,
                fn (): array => [
                    'id' => $this->resource->commune->id,
                    'code' => $this->resource->commune->code,
                    'name_kh' => $this->resource->commune->name_kh,
                    'name_en' => $this->resource->commune->name_en,
                ],
            ),
            'village' => $this->when(
                $this->resource->relationLoaded('village') && $this->resource->village !== null,
                fn (): array => [
                    'id' => $this->resource->village->id,
                    'code' => $this->resource->village->code,
                    'name_kh' => $this->resource->village->name_kh,
                    'name_en' => $this->resource->village->name_en,
                ],
            ),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
