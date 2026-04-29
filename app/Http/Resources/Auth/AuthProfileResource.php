<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\EmployeeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthProfileResource extends JsonResource
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
            'name' => $this->resource->displayName(),
            'email' => $this->resource->email,
            'email_verified_at' => $this->resource->email_verified_at?->toIso8601String(),
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
            'permissions' => $this->permissionNames(),
            'permission_names' => $this->permissionNames(),
            'employee' => $this->when(
                $this->resource->relationLoaded('employee') && $this->resource->employee !== null,
                fn (): array => EmployeeResource::make($this->resource->employee)->resolve($request),
            ),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(): array
    {
        return $this->resource->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }
}
