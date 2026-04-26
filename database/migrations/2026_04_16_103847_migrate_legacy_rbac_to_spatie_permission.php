<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->addGuardNameColumns();
        $this->createSpatiePivotTables();
        $this->migrateLegacyAssignments();
        $this->seedCanonicalPermissions();
        $this->dropLegacyPivotTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->recreateLegacyPivotTables();
        $this->restoreLegacyAssignments();

        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');

        if (Schema::hasColumn('roles', 'guard_name')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropColumn('guard_name');
            });
        }

        if (Schema::hasColumn('permissions', 'guard_name')) {
            Schema::table('permissions', function (Blueprint $table): void {
                $table->dropColumn('guard_name');
            });
        }
    }

    private function addGuardNameColumns(): void
    {
        if (! Schema::hasColumn('roles', 'guard_name')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->string('guard_name', 50)->default('api')->after('name');
            });
        }

        if (! Schema::hasColumn('permissions', 'guard_name')) {
            Schema::table('permissions', function (Blueprint $table): void {
                $table->string('guard_name', 50)->default('api')->after('name');
            });
        }

        DB::table('roles')
            ->whereNull('guard_name')
            ->update(['guard_name' => 'api']);

        DB::table('permissions')
            ->whereNull('guard_name')
            ->update(['guard_name' => 'api']);
    }

    private function createSpatiePivotTables(): void
    {
        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table): void {
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->primary(
                    ['permission_id', 'model_id', 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table): void {
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->primary(
                    ['role_id', 'model_id', 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table): void {
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->primary(
                    ['permission_id', 'role_id'],
                    'role_has_permissions_permission_id_role_id_primary'
                );
            });
        }
    }

    private function migrateLegacyAssignments(): void
    {
        if (Schema::hasTable('user_roles')) {
            $assignments = DB::table('user_roles')
                ->select(['role_id', 'user_id'])
                ->get();

            foreach ($assignments as $assignment) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $assignment->role_id,
                    'model_type' => User::class,
                    'model_id' => $assignment->user_id,
                ]);
            }
        }

        if (Schema::hasTable('role_permissions')) {
            $assignments = DB::table('role_permissions')
                ->select(['permission_id', 'role_id'])
                ->get();

            foreach ($assignments as $assignment) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $assignment->permission_id,
                    'role_id' => $assignment->role_id,
                ]);
            }
        }
    }

    private function seedCanonicalPermissions(): void
    {
        $now = now();

        foreach ($this->canonicalPermissions() as $name => $description) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'description' => $description,
                    'guard_name' => 'api',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->canonicalPermissions()))
            ->pluck('id', 'name');

        $roleIds = DB::table('roles')
            ->whereIn('name', array_keys($this->rolePermissionMap()))
            ->pluck('id', 'name');

        foreach ($this->rolePermissionMap() as $roleName => $permissions) {
            $roleId = $roleIds[$roleName] ?? null;

            if ($roleId === null) {
                continue;
            }

            foreach ($permissions as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;

                if ($permissionId === null) {
                    continue;
                }

                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    private function dropLegacyPivotTables(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('user_roles');
    }

    private function recreateLegacyPivotTables(): void
    {
        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('role_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->index()->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['role_id', 'permission_id']);
            });
        }

        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->index()->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'role_id']);
            });
        }
    }

    private function restoreLegacyAssignments(): void
    {
        if (Schema::hasTable('model_has_roles')) {
            $assignments = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->select(['role_id', 'model_id'])
                ->get();

            foreach ($assignments as $assignment) {
                DB::table('user_roles')->insertOrIgnore([
                    'role_id' => $assignment->role_id,
                    'user_id' => $assignment->model_id,
                    'created_at' => null,
                    'updated_at' => null,
                ]);
            }
        }

        if (Schema::hasTable('role_has_permissions')) {
            $assignments = DB::table('role_has_permissions')
                ->select(['permission_id', 'role_id'])
                ->get();

            foreach ($assignments as $assignment) {
                DB::table('role_permissions')->insertOrIgnore([
                    'permission_id' => $assignment->permission_id,
                    'role_id' => $assignment->role_id,
                    'created_at' => null,
                    'updated_at' => null,
                ]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function canonicalPermissions(): array
    {
        return [
            'audit-log.export' => 'Export audit logs',
            'audit-log.view' => 'View audit logs',
            'attendance.audit.view' => 'View attendance audit logs',
            'attendance.correction.request' => 'Submit attendance correction requests',
            'attendance.export' => 'Export attendance reports for the allowed scope',
            'attendance.export.any' => 'Export attendance reports for any employee',
            'attendance.manage' => 'Manage attendance records and correction workflows',
            'attendance.missing.request' => 'Submit missing attendance requests',
            'attendance.record' => 'Record personal attendance events',
            'attendance.summary.any' => 'View organization attendance summaries',
            'attendance.summary.self' => 'View personal attendance summaries',
            'attendance.view' => 'View attendance records',
            'attendance.view.any' => 'View attendance records for any employee',
            'attendance.view.self' => 'View personal attendance records',
            'employee.export' => 'Export employee records',
            'employee.manage' => 'Manage employee records and related details',
            'employee.user-link.view' => 'View users available for employee linking',
            'employee.view' => 'View employee records',
            'employee.view.any' => 'View any employee record',
            'employee.view.self' => 'View the authenticated employee record',
            'leave.approve.hr' => 'Approve leave at the HR authority stage',
            'leave.balance.view.self' => 'View personal leave balances',
            'leave.request.cancel.self' => 'Cancel personal leave requests',
            'leave.request.create' => 'Create personal leave requests',
            'leave.request.view.any' => 'View all leave requests',
            'leave.request.view.assigned' => 'View leave requests assigned for review',
            'leave.request.view.self' => 'View personal leave requests',
            'leave.type.view' => 'View leave types and policies',
            'location.view' => 'View location lookups',
            'position.view' => 'View positions',
            'role.assign' => 'Assign role and permission groups',
            'role.view' => 'View available role groups',
            'user.manage' => 'Manage system users',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rolePermissionMap(): array
    {
        return [
            'admin' => [
                'audit-log.export',
                'audit-log.view',
                'attendance.audit.view',
                'attendance.export',
                'attendance.export.any',
                'attendance.summary.any',
                'attendance.view',
                'attendance.view.any',
                'employee.manage',
                'employee.view',
                'employee.view.any',
                'leave.balance.view.self',
                'leave.request.cancel.self',
                'leave.request.create',
                'leave.request.view.any',
                'leave.request.view.self',
                'leave.type.view',
                'location.view',
                'position.view',
                'role.assign',
                'role.view',
                'user.manage',
            ],
            'employee' => [
                'attendance.correction.request',
                'attendance.export',
                'attendance.missing.request',
                'attendance.record',
                'attendance.summary.self',
                'attendance.view',
                'attendance.view.self',
                'employee.view',
                'employee.view.self',
                'leave.balance.view.self',
                'leave.request.cancel.self',
                'leave.request.create',
                'leave.request.view.self',
                'leave.type.view',
                'location.view',
            ],
            'hr' => [
                'attendance.correction.request',
                'attendance.export',
                'attendance.export.any',
                'attendance.manage',
                'attendance.missing.request',
                'attendance.record',
                'attendance.summary.any',
                'attendance.summary.self',
                'attendance.view',
                'attendance.view.any',
                'attendance.view.self',
                'employee.export',
                'employee.manage',
                'employee.user-link.view',
                'employee.view',
                'employee.view.any',
                'leave.balance.view.self',
                'leave.request.cancel.self',
                'leave.request.create',
                'leave.request.view.any',
                'leave.request.view.self',
                'leave.type.view',
                'location.view',
                'position.view',
            ],
            'hr_approver' => [
                'leave.approve.hr',
                'leave.request.view.any',
            ],
            'manager' => [
                'leave.balance.view.self',
                'leave.request.cancel.self',
                'leave.request.create',
                'leave.request.view.assigned',
                'leave.request.view.self',
                'leave.type.view',
            ],
        ];
    }
};
