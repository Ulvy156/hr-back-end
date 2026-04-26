<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'payroll_month',
    'status',
    'company_working_days',
    'monthly_working_hours',
    'employee_count',
    'total_base_salary',
    'total_prorated_base_salary',
    'total_overtime_pay',
    'total_unpaid_leave_deduction',
    'total_tax_amount',
    'total_net_salary',
])]
class PayrollRun extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_month' => 'date',
            'company_working_days' => 'integer',
            'monthly_working_hours' => 'integer',
            'employee_count' => 'integer',
            'total_base_salary' => 'decimal:2',
            'total_prorated_base_salary' => 'decimal:2',
            'total_overtime_pay' => 'decimal:2',
            'total_unpaid_leave_deduction' => 'decimal:2',
            'total_tax_amount' => 'decimal:2',
            'total_net_salary' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class)->orderBy('employee_name_snapshot');
    }
}
