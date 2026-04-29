<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * @var array<int, string>
     */
    private const USER_RELATIONS = [
        'roles',
        'employee.department',
        'employee.currentPosition',
        'employee.manager',
        'employee.branch',
        'employee.shift',
    ];

    /**
     * @var array<int, string>
     */
    private const ACCESS_RELATIONS = [
        'roles.permissions',
        'permissions',
        'employee.department',
        'employee.currentPosition',
        'employee.manager',
        'employee.branch',
        'employee.shift',
    ];

    /**
     * @param  array{search?: string|null, role_id?: int|string|null, employee_id?: int|string|null, employee_status?: string|null}  $filters
     */
    public function paginateUsers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->with(self::USER_RELATIONS)
            ->when(
                filled($filters['search'] ?? null),
                fn (Builder $query): Builder => $this->applySearch($query, (string) $filters['search'])
            )
            ->when(
                filled($filters['role_id'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'roles',
                    fn (Builder $roleQuery): Builder => $roleQuery->whereKey($filters['role_id'])
                )
            )
            ->when(
                filled($filters['employee_id'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'employee',
                    fn (Builder $employeeQuery): Builder => $employeeQuery->whereKey($filters['employee_id'])
                )
            )
            ->when(
                filled($filters['employee_status'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'employee',
                    fn (Builder $employeeQuery): Builder => $employeeQuery->where('status', $filters['employee_status'])
                )
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function __construct(private AuditLogService $auditLogService) {}

    public function getUser(User $user): User
    {
        return $user->load(self::USER_RELATIONS);
    }

    public function getUserAccessSummary(User $user): User
    {
        return $user->load(self::ACCESS_RELATIONS);
    }

    /**
     * @return Collection<int, User>
     */
    public function listAvailableForEmployeeLinking(): Collection
    {
        return User::query()
            ->with('roles')
            ->whereDoesntHave('employee')
            ->withoutPermission(PermissionName::UserManage->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{name: string, email: string, password: string, employee_id?: int|null, role_ids?: array<int, int>}  $data
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $employee = $this->employeeForLinking($data);
            $normalizedData = $this->normalizeRoleIdsForNewUser($data);

            $user = User::query()->create([
                'name' => $this->resolvedAccountName($data, $employee),
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            if ($employee !== null) {
                $this->ensureEmployeeIsAvailable($employee);
                $employee->user()->associate($user);
                $employee->save();
            }

            $this->syncRoles($user, $normalizedData);

            return $user->load(self::USER_RELATIONS);
        });
    }

    /**
     * @param  array{name: string, email: string, password?: string|null, employee_id?: int|null, role_ids?: array<int, int>}  $data
     */
    public function updateUser(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $currentEmployee = $user->employee()
                ->lockForUpdate()
                ->first();

            $targetEmployee = $this->employeeForLinking($data);
            $nameSourceEmployee = $this->nameSourceEmployee($data, $currentEmployee, $targetEmployee);

            if ($targetEmployee !== null) {
                $this->ensureEmployeeIsAvailable($targetEmployee, $user);
            }

            $user->fill([
                'name' => $this->resolvedAccountName($data, $nameSourceEmployee),
                'email' => $data['email'],
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();

            if ($currentEmployee !== null && ($targetEmployee === null || $currentEmployee->isNot($targetEmployee))) {
                $currentEmployee->user()->dissociate();
                $currentEmployee->save();
            }

            if ($targetEmployee !== null && $targetEmployee->user_id !== $user->id) {
                $targetEmployee->user()->associate($user);
                $targetEmployee->save();
            }

            $this->syncRoles($user, $data);

            return $user->load(self::USER_RELATIONS);
        });
    }

    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $employee = $user->employee()
                ->lockForUpdate()
                ->first();

            if ($employee !== null) {
                $employee->user()->dissociate();
                $employee->save();
            }

            $user->delete();
        });
    }

    /**
     * @return Collection<int, Role>
     */
    public function listRoles(): Collection
    {
        return Role::query()
            ->with([
                'permissions' => fn ($query) => $query
                    ->select(['permissions.id', 'name'])
                    ->orderBy('name'),
            ])
            ->whereIn('name', Role::managedRoleNames())
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, string>  $roleNames
     */
    public function syncUserRoles(User $user, array $roleNames, ?User $actor = null): User
    {
        return $this->syncUserAccess($user, $actor, $roleNames, null);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function syncUserPermissions(User $user, array $permissionNames, ?User $actor = null): User
    {
        return $this->syncUserAccess($user, $actor, null, $permissionNames);
    }

    /**
     * @param  array{roles?: array<int, string>, permissions?: array<int, string>}  $data
     */
    public function syncUserAccessAssignments(User $user, array $data, ?User $actor = null): User
    {
        return $this->syncUserAccess(
            $user,
            $actor,
            $data['roles'] ?? null,
            $data['permissions'] ?? null,
        );
    }

    private function ensureEmployeeIsAvailable(Employee $employee, ?User $user = null): void
    {
        if ($employee->user_id === null) {
            return;
        }

        if ($user !== null && $employee->user_id === $user->id) {
            return;
        }

        throw ValidationException::withMessages([
            'employee_id' => 'This employee already has a user account.',
        ]);
    }

    /**
     * @param  array{employee_id?: int|null}  $data
     */
    private function employeeForLinking(array $data): ?Employee
    {
        if (! array_key_exists('employee_id', $data) || $data['employee_id'] === null) {
            return null;
        }

        return Employee::query()
            ->lockForUpdate()
            ->findOrFail($data['employee_id']);
    }

    /**
     * @param  array{name: string}  $data
     */
    private function resolvedAccountName(array $data, ?Employee $employee = null): string
    {
        return $employee?->full_name ?: $data['name'];
    }

    /**
     * @param  array{employee_id?: int|null}  $data
     */
    private function nameSourceEmployee(array $data, ?Employee $currentEmployee, ?Employee $targetEmployee): ?Employee
    {
        if (array_key_exists('employee_id', $data)) {
            return $targetEmployee;
        }

        return $currentEmployee;
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);
        $fullNameExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "first_name || ' ' || last_name"
            : "CONCAT(first_name, ' ', last_name)";

        return $query->where(function (Builder $userQuery) use ($fullNameExpression, $normalizedSearch): void {
            $userQuery
                ->where('users.name', 'like', '%'.$normalizedSearch.'%')
                ->orWhere('users.email', 'like', '%'.$normalizedSearch.'%')
                ->orWhereHas('employee', function (Builder $employeeQuery) use ($fullNameExpression, $normalizedSearch): void {
                    $employeeQuery
                        ->where('employee_code', 'like', '%'.$normalizedSearch.'%')
                        ->orWhere('first_name', 'like', '%'.$normalizedSearch.'%')
                        ->orWhere('last_name', 'like', '%'.$normalizedSearch.'%')
                        ->orWhereRaw($fullNameExpression.' LIKE ?', ['%'.$normalizedSearch.'%'])
                        ->orWhere('email', 'like', '%'.$normalizedSearch.'%');
                });
        });
    }

    /**
     * @param  array{role_ids?: array<int, int>}  $data
     */
    private function syncRoles(User $user, array $data): void
    {
        if (! array_key_exists('role_ids', $data) || ! is_array($data['role_ids'])) {
            return;
        }

        if (count($data['role_ids']) > 1) {
            throw ValidationException::withMessages([
                'role_ids' => 'A user may only have one role.',
            ]);
        }

        $this->ensureManagedRoleIds($data['role_ids']);

        $user->syncRoles($data['role_ids']);
    }

    /**
     * @param  array{name: string, email: string, password: string, employee_id?: int|null, role_ids?: array<int, int>}  $data
     * @return array{name: string, email: string, password: string, employee_id?: int|null, role_ids: array<int, int>}
     */
    private function normalizeRoleIdsForNewUser(array $data): array
    {
        $roleIds = $data['role_ids'] ?? null;

        if (is_array($roleIds) && $roleIds !== []) {
            return $data;
        }

        $employeeRoleId = Role::query()
            ->where('name', 'employee')
            ->where('guard_name', 'api')
            ->value('id');

        if (! is_int($employeeRoleId)) {
            throw ValidationException::withMessages([
                'role_ids' => 'The default employee role is not available.',
            ]);
        }

        $data['role_ids'] = [$employeeRoleId];

        return $data;
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    private function ensureManagedRoleIds(array $roleIds): void
    {
        $normalizedRoleIds = collect($roleIds)
            ->map(fn (int|string $roleId): int => (int) $roleId)
            ->unique()
            ->values();

        if ($normalizedRoleIds->isEmpty()) {
            return;
        }

        $managedRoleCount = Role::query()
            ->whereIn('id', $normalizedRoleIds)
            ->whereIn('name', Role::managedRoleNames())
            ->where('guard_name', 'api')
            ->count();

        if ($managedRoleCount === $normalizedRoleIds->count()) {
            return;
        }

        throw ValidationException::withMessages([
            'role_ids' => 'Only managed roles may be assigned to users.',
        ]);
    }

    /**
     * @param  array<int, string>|null  $roleNames
     * @param  array<int, string>|null  $permissionNames
     */
    private function syncUserAccess(
        User $user,
        ?User $actor,
        ?array $roleNames,
        ?array $permissionNames,
    ): User {
        return DB::transaction(function () use ($actor, $permissionNames, $roleNames, $user): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->load(self::ACCESS_RELATIONS);

            $before = $this->accessSnapshot($lockedUser);
            $targetRoleNames = $roleNames !== null ? $this->normalizeNames($roleNames) : $before['roles'];
            $targetDirectPermissionNames = $permissionNames !== null
                ? $this->normalizeNames($permissionNames)
                : $before['direct_permissions'];

            if (count($targetRoleNames) > 1) {
                throw ValidationException::withMessages([
                    'roles' => 'A user may only have one role.',
                ]);
            }

            $this->ensureAnotherAccessAdministratorRemains(
                $lockedUser,
                $targetRoleNames,
                $targetDirectPermissionNames,
            );

            if ($roleNames !== null) {
                $lockedUser->syncRoles($targetRoleNames);
            }

            if ($permissionNames !== null) {
                $lockedUser->syncPermissions($targetDirectPermissionNames);
            }

            $lockedUser->load(self::ACCESS_RELATIONS);

            $after = $this->accessSnapshot($lockedUser);

            if ($before !== $after) {
                $this->auditLogService->log(
                    logName: 'access_control',
                    event: 'user_access_updated',
                    description: 'user.access_updated',
                    causer: $actor,
                    subject: $lockedUser,
                    properties: [
                        'target_user_id' => $lockedUser->id,
                        'old_roles' => $before['roles'],
                        'new_roles' => $after['roles'],
                        'old_direct_permissions' => $before['direct_permissions'],
                        'new_direct_permissions' => $after['direct_permissions'],
                        'old_effective_permissions' => $before['effective_permissions'],
                        'new_effective_permissions' => $after['effective_permissions'],
                    ],
                );
            }

            return $lockedUser;
        });
    }

    /**
     * @param  array<int, string>  $targetRoleNames
     * @param  array<int, string>  $targetDirectPermissionNames
     */
    private function ensureAnotherAccessAdministratorRemains(
        User $user,
        array $targetRoleNames,
        array $targetDirectPermissionNames,
    ): void {
        if (! $this->hasAccessAdministrationPermissions($this->accessSnapshot($user)['effective_permissions'])) {
            return;
        }

        $nextEffectivePermissions = $this->resolveEffectivePermissionNames($targetRoleNames, $targetDirectPermissionNames);

        if ($this->hasAccessAdministrationPermissions($nextEffectivePermissions)) {
            return;
        }

        $anotherAdministratorExists = User::query()
            ->whereKeyNot($user->id)
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->contains(fn (User $candidate): bool => $this->hasAccessAdministrationPermissions(
                $this->accessSnapshot($candidate)['effective_permissions']
            ));

        if ($anotherAdministratorExists) {
            return;
        }

        throw ValidationException::withMessages([
            'access' => 'At least one user must retain access-management permissions.',
        ]);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function hasAccessAdministrationPermissions(array $permissionNames): bool
    {
        return collect(PermissionName::accessManagementValues())
            ->every(fn (string $permission): bool => in_array($permission, $permissionNames, true));
    }

    /**
     * @return array{roles: array<int, string>, direct_permissions: array<int, string>, effective_permissions: array<int, string>}
     */
    private function accessSnapshot(User $user): array
    {
        $user->loadMissing(['roles.permissions', 'permissions']);

        return [
            'roles' => $user->roles
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'direct_permissions' => $user->getDirectPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
            'effective_permissions' => $user->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, string>  $roleNames
     * @param  array<int, string>  $directPermissionNames
     * @return array<int, string>
     */
    private function resolveEffectivePermissionNames(array $roleNames, array $directPermissionNames): array
    {
        $rolePermissionNames = Role::query()
            ->whereIn('name', $roleNames)
            ->with([
                'permissions' => fn ($query) => $query
                    ->select(['permissions.id', 'name'])
                    ->orderBy('name'),
            ])
            ->get()
            ->flatMap(fn (Role $role): array => $role->permissions->pluck('name')->all())
            ->all();

        return $this->normalizeNames([
            ...$rolePermissionNames,
            ...$directPermissionNames,
        ]);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function normalizeNames(array $names): array
    {
        return collect($names)
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => trim($name))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
