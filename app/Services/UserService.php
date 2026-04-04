<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function paginateUsers(int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->with('employee')
            ->paginate($perPage);
    }

    public function getUser(User $user): User
    {
        return $user->load('employee');
    }

    /**
     * @param array{name: string, email: string, password: string, employee_id: int} $data
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

            return $user->load('employee');
        });
    }

    /**
     * @param array{name: string, email: string, password?: string|null, employee_id: int} $data
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

            return $user->load('employee');
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
}
