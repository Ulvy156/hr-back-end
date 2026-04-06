<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeEducationResource extends JsonResource
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
            'institution_name' => $this->resource->institution_name,
            'education_level' => $this->resource->education_level?->value,
            'degree' => $this->resource->degree,
            'field_of_study' => $this->resource->field_of_study,
            'start_date' => $this->resource->start_date?->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),
            'graduation_year' => $this->resource->graduation_year,
            'grade' => $this->resource->grade,
            'description' => $this->resource->description,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
