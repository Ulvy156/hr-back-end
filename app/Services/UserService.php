<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
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

    public function getUser(User $user): User
    {
        return $user->load(self::USER_RELATIONS);
    }

    /**
     * @param  array{name: string, email: string, password: string, employee_id: int, role_ids?: array<int, int>}  $data
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($data['employee_id']);

            $this->ensureEmployeeIsAvailable($employee);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $employee->user()->associate($user);
            $employee->save();

            $this->syncRoles($user, $data);

            return $user->load(self::USER_RELATIONS);
        });
    }

    /**
     * @param  array{name: string, email: string, password?: string|null, employee_id: int, role_ids?: array<int, int>}  $data
     */
    public function updateUser(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $currentEmployee = $user->employee()
                ->lockForUpdate()
                ->first();

            $targetEmployee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($data['employee_id']);

            $this->ensureEmployeeIsAvailable($targetEmployee, $user);

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();

            if ($currentEmployee !== null && $currentEmployee->isNot($targetEmployee)) {
                $currentEmployee->user()->dissociate();
                $currentEmployee->save();
            }

            if ($targetEmployee->user_id !== $user->id) {
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
            ->orderBy('name')
            ->get();
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

    private function applySearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        return $query->where(function (Builder $userQuery) use ($normalizedSearch): void {
            $userQuery
                ->where('users.name', 'like', '%'.$normalizedSearch.'%')
                ->orWhere('users.email', 'like', '%'.$normalizedSearch.'%')
                ->orWhereHas('employee', function (Builder $employeeQuery) use ($normalizedSearch): void {
                    $employeeQuery
                        ->where('employee_code', 'like', '%'.$normalizedSearch.'%')
                        ->orWhere('first_name', 'like', '%'.$normalizedSearch.'%')
                        ->orWhere('last_name', 'like', '%'.$normalizedSearch.'%')
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ['%'.$normalizedSearch.'%'])
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

        $user->roles()->sync($data['role_ids']);
    }
}
