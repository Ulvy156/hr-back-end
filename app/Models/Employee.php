<?php

namespace App\Models;

use App\EmergencyContactRelationship;
use App\EmployeeGender;
use App\EmployeeIdType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'department_id',
    'current_position_id',
    'manager_id',
    'first_name',
    'last_name',
    'email',
    'phone',
    'date_of_birth',
    'gender',
    'personal_phone',
    'personal_email',
    'current_address',
    'permanent_address',
    'id_type',
    'id_number',
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_phone',
    'hire_date',
    'status',
])]
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $appends = ['full_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'date_of_birth' => 'date',
            'gender' => EmployeeGender::class,
            'id_type' => EmployeeIdType::class,
            'emergency_contact_relationship' => EmergencyContactRelationship::class,
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim($this->first_name.' '.$this->last_name),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function currentPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'current_position_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'current_position_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function positionHistory(): HasMany
    {
        return $this->hasMany(EmployeePosition::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function attendanceCorrectionRequests(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionRequest::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function managerApprovedLeaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'manager_approved_by');
    }

    public function hrApprovedLeaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'hr_approved_by');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }
}
