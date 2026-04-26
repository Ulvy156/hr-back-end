<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalary;
use Illuminate\Support\Carbon;

class EmployeeSalaryResolver
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    /**
     * @return array{
     *     salary_source: 'employee_salaries'|'employee_positions_fallback'|'missing',
     *     employee_salary_id: int|null,
     *     employee_position_id: int|null,
     *     amount: string|null,
     *     effective_date: string|null,
     *     end_date: string|null
     * }
     */
    public function resolveForDate(Employee $employee, Carbon|string $referenceDate): array
    {
        $referenceDate = $referenceDate instanceof Carbon
            ? $referenceDate->copy()->startOfDay()
            : Carbon::parse($referenceDate)->startOfDay();

        $employeeSalary = $employee->employeeSalaries()
            ->activeOn($referenceDate)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($employeeSalary instanceof EmployeeSalary) {
            return [
                'salary_source' => 'employee_salaries',
                'employee_salary_id' => $employeeSalary->id,
                'employee_position_id' => null,
                'amount' => $employeeSalary->amount,
                'effective_date' => $employeeSalary->effective_date?->toDateString(),
                'end_date' => $employeeSalary->end_date?->toDateString(),
            ];
        }

        if ($employee->employeeSalaries()->exists()) {
            return $this->missingResult();
        }

        $employeePosition = $employee->employeePositions()
            ->activeOn($referenceDate)
            ->where('base_salary', '>', 0)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if ($employeePosition instanceof EmployeePosition) {
            return [
                'salary_source' => 'employee_positions_fallback',
                'employee_salary_id' => null,
                'employee_position_id' => $employeePosition->id,
                'amount' => $employeePosition->base_salary,
                'effective_date' => $employeePosition->start_date?->toDateString(),
                'end_date' => $employeePosition->end_date?->toDateString(),
            ];
        }

        return $this->missingResult();
    }

    /**
     * @return array{
     *     salary_source: 'missing',
     *     employee_salary_id: null,
     *     employee_position_id: null,
     *     amount: null,
     *     effective_date: null,
     *     end_date: null
     * }
     */
    private function missingResult(): array
    {
        return [
            'salary_source' => 'missing',
            'employee_salary_id' => null,
            'employee_position_id' => null,
            'amount' => null,
            'effective_date' => null,
            'end_date' => null,
        ];
    }
}
