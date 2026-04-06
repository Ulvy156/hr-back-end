<?php

namespace App\Models;

use App\EmployeeEducationLevel;
use Database\Factories\EmployeeEducationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeEducation extends Model
{
    /** @use HasFactory<EmployeeEducationFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'employee_educations';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_id',
        'institution_name',
        'education_level',
        'degree',
        'field_of_study',
        'start_date',
        'end_date',
        'graduation_year',
        'grade',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'education_level' => EmployeeEducationLevel::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'graduation_year' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employee')
            ->logOnly([
                'employee_id',
                'institution_name',
                'education_level',
                'degree',
                'field_of_study',
                'start_date',
                'end_date',
                'graduation_year',
                'grade',
            ])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
