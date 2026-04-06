<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\EmployeeEducation;
use Illuminate\Database\Eloquent\Collection;

class EmployeeEducationService
{
    /**
     * @return Collection<int, EmployeeEducation>
     */
    public function index(int $employeeId): Collection
    {
        $this->findEmployee($employeeId);

        return EmployeeEducation::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('end_date')
            ->orderByDesc('graduation_year')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $employeeId, array $data): EmployeeEducation
    {
        $employee = $this->findEmployee($employeeId);

        return $employee->educations()->create($data)->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $employeeId, int $educationId, array $data): EmployeeEducation
    {
        $education = $this->findEducation($employeeId, $educationId);
        $education->update($data);

        return $education->fresh();
    }

    public function delete(int $employeeId, int $educationId): void
    {
        $education = $this->findEducation($employeeId, $educationId);
        $education->delete();
    }

    private function findEmployee(int $employeeId): Employee
    {
        return Employee::query()->findOrFail($employeeId);
    }

    private function findEducation(int $employeeId, int $educationId): EmployeeEducation
    {
        $this->findEmployee($employeeId);

        return EmployeeEducation::query()
            ->where('employee_id', $employeeId)
            ->findOrFail($educationId);
    }
}
