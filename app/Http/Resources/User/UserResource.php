<?php

namespace App\Http\Resources\User;

use App\Http\Resources\EmployeeIndexResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->resource->email,
            'email_verified_at' => $this->resource->email_verified_at?->toIso8601String(),
            'employee_id' => $this->resource->employee?->id,
            'employee' => $this->when(
                $this->resource->relationLoaded('employee') && $this->resource->employee !== null,
                fn (): array => EmployeeIndexResource::make($this->resource->employee)->resolve($request),
            ),
            'roles' => $this->when(
                $this->resource->relationLoaded('roles'),
                fn (): array => $this->resource->roles
                    ->map(fn ($role): array => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'description' => $role->description,
                    ])
                    ->values()
                    ->all(),
            ),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
