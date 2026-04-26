<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailableEmployeeUserResource extends JsonResource
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
        ];
    }
}
