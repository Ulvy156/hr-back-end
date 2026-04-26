<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payroll_run_id',
    'employee_id',
    'employee_salary_id',
    'salary_source',
    'employee_code_snapshot',
    'employee_name_snapshot',
    'base_salary',
    'prorated_base_salary',
    'hourly_rate',
    'daily_rate',
    'eligible_working_days',
    'company_working_days',
    'monthly_working_hours',
    'overtime_normal_hours',
    'overtime_weekend_hours',
    'overtime_holiday_hours',
    'overtime_pay',
    'unpaid_leave_units',
    'unpaid_leave_deduction',
    'tax_amount',
    'raw_net_salary',
    'net_salary',
])]
class PayrollItem extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'prorated_base_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'daily_rate' => 'decimal:4',
            'eligible_working_days' => 'integer',
            'company_working_days' => 'integer',
            'monthly_working_hours' => 'integer',
            'overtime_normal_hours' => 'decimal:2',
            'overtime_weekend_hours' => 'decimal:2',
            'overtime_holiday_hours' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'unpaid_leave_units' => 'decimal:2',
            'unpaid_leave_deduction' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'raw_net_salary' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeeSalary(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalary::class);
    }
}
