<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $hrRoleId = $this->ensureHrRole();
        $permissionId = $this->ensureHrApprovalPermission();

        if ($hrRoleId !== null && $permissionId !== null && Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $hrRoleId,
                'permission_id' => $permissionId,
            ]);
        }

        $legacyRoleId = DB::table('roles')
            ->where('name', 'hr_approver')
            ->value('id');

        if ($legacyRoleId === null) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        if ($hrRoleId !== null && Schema::hasTable('model_has_roles')) {
            $assignedUserIds = DB::table('model_has_roles')
                ->where('role_id', $legacyRoleId)
                ->where('model_type', User::class)
                ->pluck('model_id');

            foreach ($assignedUserIds as $userId) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $hrRoleId,
                    'model_type' => User::class,
                    'model_id' => $userId,
                ]);
            }

            DB::table('model_has_roles')
                ->where('role_id', $legacyRoleId)
                ->delete();
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')
                ->where('role_id', $legacyRoleId)
                ->delete();
        }

        DB::table('roles')
            ->where('id', $legacyRoleId)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $legacyRoleId = DB::table('roles')
            ->where('name', 'hr_approver')
            ->value('id');

        if ($legacyRoleId === null) {
            DB::table('roles')->insert($this->rolePayload(
                name: 'hr_approver',
                description: 'Users with HR leave approval authority',
            ));

            $legacyRoleId = DB::table('roles')
                ->where('name', 'hr_approver')
                ->value('id');
        }

        $permissionId = DB::table('permissions')
            ->where('name', 'leave.approve.hr')
            ->value('id');

        if ($legacyRoleId !== null && $permissionId !== null && Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $legacyRoleId,
                'permission_id' => $permissionId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureHrRole(): ?int
    {
        $hrRoleId = DB::table('roles')
            ->where('name', 'hr')
            ->value('id');

        if ($hrRoleId !== null) {
            return $hrRoleId;
        }

        DB::table('roles')->insert($this->rolePayload(
            name: 'hr',
            description: 'Human resources staff',
        ));

        return DB::table('roles')
            ->where('name', 'hr')
            ->value('id');
    }

    private function ensureHrApprovalPermission(): ?int
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'leave.approve.hr')
            ->value('id');

        if ($permissionId !== null) {
            DB::table('permissions')
                ->where('id', $permissionId)
                ->update($this->permissionPayload(
                    description: 'Approve leave at the HR authority stage',
                ));

            return $permissionId;
        }

        DB::table('permissions')->insert($this->permissionPayload(
            name: 'leave.approve.hr',
            description: 'Approve leave at the HR authority stage',
        ));

        return DB::table('permissions')
            ->where('name', 'leave.approve.hr')
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function rolePayload(string $name, string $description): array
    {
        $payload = [
            'name' => $name,
            'description' => $description,
            'guard_name' => 'api',
        ];

        if (Schema::hasColumn('roles', 'created_at')) {
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
        }

        if (Schema::hasColumn('roles', 'deleted_at')) {
            $payload['deleted_at'] = null;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionPayload(?string $name = null, string $description = ''): array
    {
        $payload = [
            'description' => $description,
            'guard_name' => 'api',
        ];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if (Schema::hasColumn('permissions', 'created_at')) {
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
        } elseif (Schema::hasColumn('permissions', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        if (Schema::hasColumn('permissions', 'deleted_at')) {
            $payload['deleted_at'] = null;
        }

        return $payload;
    }
};
