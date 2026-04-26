<?php

use App\Models\Role;
use App\PermissionName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissions() as $name => $description) {
            DB::table('permissions')->updateOrInsert(
                [
                    'name' => $name,
                    'guard_name' => 'api',
                ],
                [
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'api')
            ->value('id');

        if ($adminRoleId !== null) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', array_keys($this->permissions()))
                ->where('guard_name', 'api')
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $adminRoleId,
                ]);
            }
        }

        $allPermissionIds = DB::table('permissions')
            ->whereIn('name', PermissionName::values())
            ->where('guard_name', 'api')
            ->pluck('id', 'name');

        $roleIds = DB::table('roles')
            ->whereIn('name', Role::managedRoleNames())
            ->where('guard_name', 'api')
            ->pluck('id', 'name');

        foreach (Role::managedRoleNames() as $roleName) {
            $roleId = $roleIds[$roleName] ?? null;

            if ($roleId === null) {
                continue;
            }

            $expectedPermissionIds = collect(Role::defaultPermissionNamesFor($roleName))
                ->map(fn (string $permissionName): ?int => $allPermissionIds[$permissionName] ?? null)
                ->filter()
                ->values()
                ->all();

            if ($expectedPermissionIds === []) {
                DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->delete();

                continue;
            }

            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->whereNotIn('permission_id', $expectedPermissionIds)
                ->delete();

            foreach ($expectedPermissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->permissions()))
            ->where('guard_name', 'api')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, string>
     */
    private function permissions(): array
    {
        return [
            PermissionName::PermissionManage->value => 'Manage permissions',
            PermissionName::PermissionView->value => 'View permissions',
            PermissionName::RoleManage->value => 'Manage roles',
            PermissionName::UserPermissionAssign->value => 'Assign direct permissions to users',
            PermissionName::UserRoleAssign->value => 'Assign roles to users',
            PermissionName::UserUpdate->value => 'Update users',
            PermissionName::UserView->value => 'View users',
        ];
    }
};
