<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'employee_id',
    'type',
    'reason',
    'duration_type',
    'half_day_session',
    'start_date',
    'end_date',
    'manager_approved_by',
    'manager_approved_at',
    'hr_approved_by',
    'hr_approved_at',
    'status',
])]
class LeaveRequest extends Model
{
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_type' => 'string',
            'half_day_session' => 'string',
            'start_date' => 'date',
            'end_date' => 'date',
            'manager_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('leave')
            ->logOnly([
                'employee_id',
                'type',
                'reason',
                'duration_type',
                'half_day_session',
                'start_date',
                'end_date',
                'manager_approved_by',
                'manager_approved_at',
                'hr_approved_by',
                'hr_approved_at',
                'status',
            ])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'type', 'code');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_approved_by');
    }

    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hr_approved_by');
    }
}
