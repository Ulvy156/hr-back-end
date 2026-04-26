<?php

namespace App\Http\Resources\User;

use App\Http\Resources\EmployeeIndexResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAccessResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rolePermissions = $this->resource->roles
            ->flatMap(fn ($role): array => $role->permissions->pluck('name')->all())
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->displayName(),
            'email' => $this->resource->email,
            'employee_id' => $this->resource->employee?->id,
            'employee' => $this->when(
                $this->resource->relationLoaded('employee') && $this->resource->employee !== null,
                fn (): array => EmployeeIndexResource::make($this->resource->employee)->resolve($request),
            ),
            'roles' => $this->resource->roles
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions' => $role->permissions
                        ->pluck('name')
                        ->sort()
                        ->values()
                        ->all(),
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'direct_permissions' => $this->resource->getDirectPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'role_permissions' => $rolePermissions,
            'effective_permissions' => $this->resource->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
