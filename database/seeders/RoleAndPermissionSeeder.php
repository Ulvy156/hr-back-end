<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\PermissionName;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::withTrashed()
            ->whereNotIn('name', PermissionName::values())
            ->forceDelete();

        $permissions = collect($this->permissionDefinitions())
            ->mapWithKeys(function (string $description, string $permissionName): array {
                $permission = Permission::withTrashed()->updateOrCreate(
                    [
                        'name' => $permissionName,
                        'guard_name' => 'api',
                    ],
                    [
                        'description' => $description,
                        'deleted_at' => null,
                    ],
                );

                return [$permissionName => $permission];
            });

        $roles = collect($this->rolePermissions())
            ->keys()
            ->mapWithKeys(function (string $roleName): array {
                $role = Role::withoutEvents(fn (): Role => Role::withTrashed()->updateOrCreate(
                    [
                        'name' => $roleName,
                        'guard_name' => 'api',
                    ],
                    [
                        'description' => Str::headline($roleName),
                        'deleted_at' => null,
                    ],
                ));

                return [$roleName => $role];
            });

        foreach ($this->rolePermissions() as $roleName => $permissionNames) {
            $role = $roles->get($roleName);

            if (! $role instanceof Role) {
                continue;
            }

            $role->permissions()->sync(
                $permissions->only($permissionNames)->pluck('id')->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, string>
     */
    private function permissionDefinitions(): array
    {
        return PermissionName::descriptions();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rolePermissions(): array
    {
        return collect(Role::managedRoleNames())
            ->mapWithKeys(fn (string $roleName): array => [
                $roleName => Role::defaultPermissionNamesFor($roleName),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(): array
    {
        return PermissionName::values();
    }
}
