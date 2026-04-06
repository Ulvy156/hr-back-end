<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\EmployeePosition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class EmployeePositionCrudService
{
    /**
     * @return Collection<int, EmployeePosition>
     */
    public function index(int $employeeId): Collection
    {
        $employee = $this->findEmployee($employeeId);

        return $employee->employeePositions()
            ->with('position')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $employeeId, array $data): EmployeePosition
    {
        $employee = $this->findEmployee($employeeId);
        $willBeCurrent = empty($data['end_date']);

        if ($willBeCurrent && $employee->employeePositions()->whereNull('end_date')->exists()) {
            throw ValidationException::withMessages([
                'end_date' => ['Only one employee position may be current.'],
            ]);
        }

        $employeePosition = $employee->employeePositions()->create($data);

        if ($willBeCurrent && $employee->current_position_id !== (int) $data['position_id']) {
            $employee->forceFill([
                'current_position_id' => $data['position_id'],
            ])->save();
        }

        return $employeePosition->fresh('position');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $employeeId, int $employeePositionId, array $data): EmployeePosition
    {
        $employee = $this->findEmployee($employeeId);
        $employeePosition = $this->findEmployeePosition($employeeId, $employeePositionId);
        $wasCurrent = $employeePosition->end_date === null;
        $willBeCurrent = empty($data['end_date']);

        if (
            $willBeCurrent &&
            $employee->employeePositions()->whereKeyNot($employeePosition->id)->whereNull('end_date')->exists()
        ) {
            throw ValidationException::withMessages([
                'end_date' => ['Only one employee position may be current.'],
            ]);
        }

        if (
            $wasCurrent &&
            ! $willBeCurrent &&
            ! $employee->employeePositions()->whereKeyNot($employeePosition->id)->whereNull('end_date')->exists()
        ) {
            throw ValidationException::withMessages([
                'end_date' => ['The employee must keep one current position.'],
            ]);
        }

        $employeePosition->update($data);

        if ($willBeCurrent && $employee->current_position_id !== (int) $employeePosition->position_id) {
            $employee->forceFill([
                'current_position_id' => $employeePosition->position_id,
            ])->save();
        }

        if ($wasCurrent && ! $willBeCurrent) {
            $replacement = $employee->employeePositions()
                ->whereKeyNot($employeePosition->id)
                ->whereNull('end_date')
                ->first();

            if ($replacement !== null && $employee->current_position_id !== $replacement->position_id) {
                $employee->forceFill([
                    'current_position_id' => $replacement->position_id,
                ])->save();
            }
        }

        return $employeePosition->fresh('position');
    }

    public function delete(int $employeeId, int $employeePositionId): void
    {
        $employee = $this->findEmployee($employeeId);
        $employeePosition = $this->findEmployeePosition($employeeId, $employeePositionId);
        $wasCurrent = $employeePosition->end_date === null;

        if (
            $wasCurrent &&
            ! $employee->employeePositions()->whereKeyNot($employeePosition->id)->whereNull('end_date')->exists()
        ) {
            throw ValidationException::withMessages([
                'employee_position' => ['The employee must keep one current position.'],
            ]);
        }

        $employeePosition->delete();

        if ($wasCurrent) {
            $replacement = $employee->employeePositions()
                ->whereNull('end_date')
                ->first();

            if ($replacement !== null && $employee->current_position_id !== $replacement->position_id) {
                $employee->forceFill([
                    'current_position_id' => $replacement->position_id,
                ])->save();
            }
        }
    }

    private function findEmployee(int $employeeId): Employee
    {
        return Employee::query()->findOrFail($employeeId);
    }

    private function findEmployeePosition(int $employeeId, int $employeePositionId): EmployeePosition
    {
        $this->findEmployee($employeeId);

        return EmployeePosition::query()
            ->where('employee_id', $employeeId)
            ->findOrFail($employeePositionId);
    }
}
