<?php

use App\Models\Role;
use App\PermissionName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            [
                'name' => PermissionName::LeaveApproveManager->value,
                'guard_name' => 'api',
            ],
            [
                'description' => 'Approve leave at the manager authority stage',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->syncManagedRolePermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('name', PermissionName::LeaveApproveManager->value)
            ->where('guard_name', 'api')
            ->value('id');

        if ($permissionId !== null) {
            if (Schema::hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')
                    ->where('permission_id', $permissionId)
                    ->delete();
            }

            if (Schema::hasTable('role_has_permissions')) {
                DB::table('role_has_permissions')
                    ->where('permission_id', $permissionId)
                    ->delete();
            }

            DB::table('permissions')
                ->where('id', $permissionId)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function syncManagedRolePermissions(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $permissionIdsByName = DB::table('permissions')
            ->whereIn('name', array_values(array_unique(array_merge(
                Role::defaultPermissionNamesFor('admin'),
                Role::defaultPermissionNamesFor('employee'),
                Role::defaultPermissionNamesFor('hr'),
                Role::defaultPermissionNamesFor('manager'),
            ))))
            ->pluck('id', 'name');

        $roleIdsByName = DB::table('roles')
            ->whereIn('name', Role::managedRoleNames())
            ->pluck('id', 'name');

        foreach (Role::managedRoleNames() as $roleName) {
            $roleId = $roleIdsByName[$roleName] ?? null;

            if ($roleId === null) {
                continue;
            }

            $expectedPermissionIds = collect(Role::defaultPermissionNamesFor($roleName))
                ->map(fn (string $permissionName): ?int => $permissionIdsByName[$permissionName] ?? null)
                ->filter()
                ->values()
                ->all();

            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->whereNotIn('permission_id', $expectedPermissionIds === [] ? [0] : $expectedPermissionIds)
                ->delete();

            foreach ($expectedPermissionIds as $expectedPermissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $expectedPermissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }
};
