<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private const LEGACY_PERMISSION_REPLACEMENTS = [
        'approve_leave_as_manager' => [PermissionName::LeaveApproveManager->value],
        'attendance.correction.review.queue' => [PermissionName::AttendanceViewAny->value],
        'attendance.export.self' => [PermissionName::AttendanceExport->value],
        'attendance.record.self' => [PermissionName::AttendanceRecord->value],
        'leave.request.review.hr' => [PermissionName::LeaveApproveHr->value],
        'leave.request.review.manager' => [PermissionName::LeaveApproveManager->value],
        'manage_employees' => [PermissionName::EmployeeManage->value],
        'manage_users' => [PermissionName::UserManage->value],
    ];

    /**
     * @var array<int, string>
     */
    private const OBSOLETE_LEGACY_PERMISSIONS = [
        'manage_departments',
        'manage_leave',
        'manage_payroll',
        'view_payroll',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $this->ensureCanonicalPermissionsExist();
            $this->migrateLegacyRolePermissions();
            $this->migrateLegacyDirectPermissions();
            $this->deleteLegacyPermissionRows();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureCanonicalPermissionsExist(): void
    {
        foreach (PermissionName::descriptions() as $permissionName => $description) {
            Permission::withTrashed()->updateOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ],
                [
                    'description' => $description,
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function migrateLegacyRolePermissions(): void
    {
        foreach (self::LEGACY_PERMISSION_REPLACEMENTS as $legacyPermission => $replacementPermissions) {
            $legacyPermissionModel = $this->permissionNamed($legacyPermission);

            if (! $legacyPermissionModel instanceof Permission) {
                continue;
            }

            $roles = Role::query()
                ->whereHas('permissions', fn ($query) => $query->whereKey($legacyPermissionModel->id))
                ->get();

            foreach ($roles as $role) {
                foreach ($replacementPermissions as $replacementPermission) {
                    if (! $role->permissions()->where('name', $replacementPermission)->exists()) {
                        $role->givePermissionTo($replacementPermission);
                    }
                }

                $role->revokePermissionTo($legacyPermission);
            }
        }
    }

    private function migrateLegacyDirectPermissions(): void
    {
        foreach (self::LEGACY_PERMISSION_REPLACEMENTS as $legacyPermission => $replacementPermissions) {
            $legacyPermissionModel = $this->permissionNamed($legacyPermission);

            if (! $legacyPermissionModel instanceof Permission) {
                continue;
            }

            $users = User::query()
                ->whereHas('permissions', fn ($query) => $query->whereKey($legacyPermissionModel->id))
                ->get();

            foreach ($users as $user) {
                foreach ($replacementPermissions as $replacementPermission) {
                    if (! $this->userHasPermission($user, $replacementPermission)) {
                        $user->givePermissionTo($replacementPermission);
                    }
                }

                $user->revokePermissionTo($legacyPermission);
            }
        }

        foreach (self::OBSOLETE_LEGACY_PERMISSIONS as $obsoletePermission) {
            $obsoletePermissionModel = $this->permissionNamed($obsoletePermission);

            if (! $obsoletePermissionModel instanceof Permission) {
                continue;
            }

            $users = User::query()
                ->whereHas('permissions', fn ($query) => $query->whereKey($obsoletePermissionModel->id))
                ->get();

            foreach ($users as $user) {
                $user->revokePermissionTo($obsoletePermission);
            }

            $roles = Role::query()
                ->whereHas('permissions', fn ($query) => $query->whereKey($obsoletePermissionModel->id))
                ->get();

            foreach ($roles as $role) {
                $role->revokePermissionTo($obsoletePermission);
            }
        }
    }

    private function deleteLegacyPermissionRows(): void
    {
        DB::table('permissions')
            ->whereNotIn('name', PermissionName::values())
            ->delete();
    }

    private function permissionNamed(string $permissionName): ?Permission
    {
        return Permission::withTrashed()
            ->where('name', $permissionName)
            ->where('guard_name', 'api')
            ->first();
    }

    private function userHasPermission(User $user, string $permissionName): bool
    {
        return $user->permissions()->where('name', $permissionName)->exists()
            || $user->roles()->whereHas('permissions', fn ($query) => $query->where('name', $permissionName))->exists();
    }
};
