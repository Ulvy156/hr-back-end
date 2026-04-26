<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $rolePriority = [
        'admin',
        'hr',
        'manager',
        'employee',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('model_has_permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $rolesById = Role::query()
            ->whereIn('name', Role::managedRoleNames())
            ->get()
            ->keyBy('id');

        $duplicateAssignments = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('role_id', $rolesById->keys())
            ->select(['model_id', 'role_id'])
            ->orderBy('model_id')
            ->get()
            ->groupBy('model_id')
            ->filter(fn (Collection $assignments): bool => $assignments->count() > 1);

        foreach ($duplicateAssignments as $userId => $assignments) {
            $roleIds = $assignments->pluck('role_id')->map(fn (mixed $roleId): int => (int) $roleId)->all();
            $primaryRoleId = $this->primaryRoleId($roleIds, $rolesById);

            if ($primaryRoleId === null) {
                continue;
            }

            $removedRoleIds = array_values(array_filter(
                $roleIds,
                fn (int $roleId): bool => $roleId !== $primaryRoleId,
            ));

            if ($removedRoleIds === []) {
                continue;
            }

            $keptPermissionIds = DB::table('role_has_permissions')
                ->where('role_id', $primaryRoleId)
                ->pluck('permission_id')
                ->map(fn (mixed $permissionId): int => (int) $permissionId)
                ->all();

            $removedPermissionIds = DB::table('role_has_permissions')
                ->whereIn('role_id', $removedRoleIds)
                ->pluck('permission_id')
                ->map(fn (mixed $permissionId): int => (int) $permissionId)
                ->unique()
                ->values()
                ->all();

            $directPermissionIds = array_values(array_diff($removedPermissionIds, $keptPermissionIds));

            foreach ($directPermissionIds as $permissionId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'model_type' => User::class,
                    'model_id' => $userId,
                ]);
            }

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $userId)
                ->whereIn('role_id', $removedRoleIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, int>  $roleIds
     * @param  Collection<int, Role>  $rolesById
     */
    private function primaryRoleId(array $roleIds, Collection $rolesById): ?int
    {
        foreach ($this->rolePriority as $roleName) {
            $match = collect($roleIds)->first(function (int $roleId) use ($roleName, $rolesById): bool {
                /** @var Role|null $role */
                $role = $rolesById->get($roleId);

                return $role?->name === $roleName;
            });

            if ($match !== null) {
                return $match;
            }
        }

        return $roleIds[0] ?? null;
    }
};
