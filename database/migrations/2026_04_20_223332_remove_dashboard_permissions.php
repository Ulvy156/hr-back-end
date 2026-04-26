<?php

use App\Models\Role;
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
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->dashboardPermissionNames())
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->delete();

        if (Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
            $permissionIdsByName = DB::table('permissions')
                ->whereIn('name', array_values(array_unique(array_merge(
                    Role::defaultPermissionNamesFor('admin'),
                    Role::defaultPermissionNamesFor('hr'),
                    Role::defaultPermissionNamesFor('manager'),
                    Role::defaultPermissionNamesFor('employee'),
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
     * @return array<int, string>
     */
    private function dashboardPermissionNames(): array
    {
        return [
            'dashboard.view.admin',
            'dashboard.view.hr',
            'dashboard.view.self',
        ];
    }
};
