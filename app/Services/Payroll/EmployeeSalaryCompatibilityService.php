<?php

namespace App\Services\Payroll;

use App\Models\EmployeePosition;
use App\Models\EmployeeSalary;
use Illuminate\Support\Carbon;

class EmployeeSalaryCompatibilityService
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    public function syncCurrentSalaryToEmployeePosition(
        EmployeeSalary $employeeSalary,
        ?Carbon $referenceDate = null,
    ): ?EmployeePosition {
        $referenceDate = $referenceDate?->copy()->startOfDay() ?? today()->startOfDay();

        if ($employeeSalary->effective_date === null || $employeeSalary->effective_date->gt($referenceDate)) {
            return null;
        }

        if ($employeeSalary->end_date !== null && $employeeSalary->end_date->lt($referenceDate)) {
            return null;
        }

        $employeePosition = $employeeSalary->employee
            ->employeePositions()
            ->activeOn($referenceDate)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if (! $employeePosition instanceof EmployeePosition) {
            return null;
        }

        if ((string) $employeePosition->base_salary === (string) $employeeSalary->amount) {
            return $employeePosition;
        }

        $employeePosition->forceFill([
            'base_salary' => $employeeSalary->amount,
        ])->save();

        return $employeePosition->refresh();
    }
}
