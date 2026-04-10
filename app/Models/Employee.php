<?php

namespace App\Models;

use App\EmergencyContactRelationship;
use App\EmployeeGender;
use App\EmployeeIdType;
use App\EmployeeStatus;
use App\EmploymentType;
use App\Services\Storage\R2StorageService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'user_id',
    'employee_code',
    'department_id',
    'branch_id',
    'current_position_id',
    'shift_id',
    'manager_id',
    'leave_approver_id',
    'first_name',
    'last_name',
    'email',
    'phone',
    'date_of_birth',
    'gender',
    'personal_phone',
    'personal_email',
    'id_type',
    'id_number',
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_phone',
    'profile_photo_path',
    'profile_photo',
    'hire_date',
    'employment_type',
    'confirmation_date',
    'termination_date',
    'last_working_date',
    'status',
])]
class Employee extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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
            'confirmation_date' => 'date',
            'termination_date' => 'date',
            'last_working_date' => 'date',
            'date_of_birth' => 'date',
            'gender' => EmployeeGender::class,
            'id_type' => EmployeeIdType::class,
            'emergency_contact_relationship' => EmergencyContactRelationship::class,
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim($this->first_name.' '.$this->last_name),
        );
    }

    protected function profilePhoto(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes): ?string {
                $storedPath = $value ?? $attributes['profile_photo_path'] ?? null;

                if ($storedPath === null || $storedPath === '') {
                    return null;
                }

                return app(R2StorageService::class)->publicUrl($storedPath);
            },
        );
    }

    protected function profilePhotoPath(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes): ?string => $value ?? $attributes['profile_photo'] ?? null,
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employee')
            ->logOnly([
                'user_id',
                'employee_code',
                'department_id',
                'branch_id',
                'current_position_id',
                'shift_id',
                'manager_id',
                'leave_approver_id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'profile_photo',
                'profile_photo_path',
                'hire_date',
                'employment_type',
                'confirmation_date',
                'termination_date',
                'last_working_date',
                'status',
            ])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currentPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'current_position_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'current_position_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function leaveApprover(): BelongsTo
    {
        return $this->belongsTo(self::class, 'leave_approver_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function positionHistory(): HasMany
    {
        return $this->employeePositions();
    }

    public function employeePositions(): HasMany
    {
        return $this->hasMany(EmployeePosition::class)
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function attendanceCorrectionRequests(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionRequest::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class)->orderByDesc('end_date')->orderByDesc('graduation_year')->orderByDesc('id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class)
            ->orderByDesc('is_primary')
            ->orderBy('id');
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

    public function profilePhotoStoragePath(): ?string
    {
        return $this->getRawOriginal('profile_photo') ?: $this->getRawOriginal('profile_photo_path');
    }
}
