<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeIndexResource extends JsonResource
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
            'employee_code' => $this->resource->employee_code,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            'full_name' => $this->resource->full_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'profile_photo' => $this->resource->profile_photo,
            'profile_photo_path' => $this->resource->profilePhotoStoragePath(),
            'hire_date' => $this->resource->hire_date?->toDateString(),
            'employment_type' => $this->resource->employment_type?->value,
            'status' => $this->resource->status?->value,
            'department' => $this->resource->department !== null ? [
                'id' => $this->resource->department->id,
                'name' => $this->resource->department->name,
            ] : null,
            'current_position' => $this->resource->currentPosition !== null ? [
                'id' => $this->resource->currentPosition->id,
                'title' => $this->resource->currentPosition->title,
            ] : null,
            'position' => $this->resource->currentPosition !== null ? [
                'id' => $this->resource->currentPosition->id,
                'title' => $this->resource->currentPosition->title,
            ] : null,
            'manager' => $this->resource->manager !== null ? [
                'id' => $this->resource->manager->id,
                'name' => $this->resource->manager->full_name,
            ] : null,
            'branch' => $this->resource->branch !== null ? [
                'id' => $this->resource->branch->id,
                'name' => $this->resource->branch->name,
                'code' => $this->resource->branch->code,
            ] : null,
            'shift' => $this->resource->shift !== null ? [
                'id' => $this->resource->shift->id,
                'name' => $this->resource->shift->name,
                'code' => $this->resource->shift->code,
                'start_time' => $this->resource->shift->start_time,
                'end_time' => $this->resource->shift->end_time,
                'late_grace_minutes' => $this->resource->shift->late_grace_minutes,
            ] : null,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
            'deleted_at' => $this->resource->deleted_at?->toIso8601String(),
        ];
    }
}
