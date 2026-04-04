<?php

namespace App\Services\Employee;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EmployeeService
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Employee
    {
        $employee = Employee::query()->create(
            $this->employeePayload($data)
        );

        return $this->loadRelations($employee->refresh());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Employee
    {
        $employee = Employee::query()->findOrFail($id);

        $employee->update(
            $this->employeePayload($data)
        );

        return $this->loadRelations($employee->refresh());
    }

    public function delete(int $id): void
    {
        $employee = Employee::query()->findOrFail($id);

        $employee->delete();
    }

    public function find(int $id): Employee
    {
        $employee = Employee::query()
            ->with($this->relations())
            ->findOrFail($id);

        return $this->decorate($employee);
    }

    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return Employee::query()
            ->with($this->relations())
            ->paginate($perPage)
            ->through(fn (Employee $employee) => $this->decorate($employee));
    }

    /**
     * @return Collection<int, Employee>
     */
    public function getByManager(int $managerId): Collection
    {
        Employee::query()->findOrFail($managerId);

        return Employee::query()
            ->with($this->relations())
            ->where('manager_id', $managerId)
            ->get()
            ->map(fn (Employee $employee) => $this->decorate($employee));
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return ['user', 'department', 'position', 'manager'];
    }

    private function loadRelations(Employee $employee): Employee
    {
        return $this->decorate(
            $employee->load($this->relations())
        );
    }

    private function decorate(Employee $employee): Employee
    {
        if ($employee->relationLoaded('position')) {
            return $employee;
        }

        return $employee->loadMissing('position');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function employeePayload(array $data): array
    {
        return [
            'user_id' => $data['user_id'] ?? null,
            'department_id' => $data['department_id'],
            'current_position_id' => $data['position_id'],
            'manager_id' => $data['manager_id'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'personal_phone' => $data['personal_phone'] ?? null,
            'personal_email' => $data['personal_email'] ?? null,
            'current_address' => $data['current_address'] ?? null,
            'permanent_address' => $data['permanent_address'] ?? null,
            'id_type' => $data['id_type'] ?? null,
            'id_number' => $data['id_number'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'hire_date' => $data['hire_date'],
            'status' => $data['status'],
        ];
    }
}
