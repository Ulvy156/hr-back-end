<?php

namespace App\Models;

use Database\Factories\OvertimeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'employee_id',
    'overtime_date',
    'start_time',
    'end_time',
    'reason',
    'status',
    'approval_stage',
    'manager_approved_by',
    'manager_approved_at',
    'rejected_by',
    'rejected_at',
    'rejection_reason',
    'minutes',
    'overtime_type',
])]
class OvertimeRequest extends Model
{
    /** @use HasFactory<OvertimeRequestFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'overtime_date' => 'date',
            'manager_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'minutes' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('overtime')
            ->logOnly([
                'employee_id',
                'overtime_date',
                'start_time',
                'end_time',
                'status',
                'approval_stage',
                'manager_approved_by',
                'manager_approved_at',
                'rejected_by',
                'rejected_at',
                'minutes',
                'overtime_type',
            ])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }
}
