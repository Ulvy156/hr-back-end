<?php

namespace App\Models;

use App\LeaveTypeGenderRestriction;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'description',
    'is_paid',
    'requires_balance',
    'requires_attachment',
    'requires_medical_certificate',
    'auto_exclude_public_holidays',
    'auto_exclude_weekends',
    'gender_restriction',
    'min_service_days',
    'max_days_per_request',
    'max_days_per_year',
    'is_active',
    'sort_order',
    'metadata',
])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_balance' => 'boolean',
            'requires_attachment' => 'boolean',
            'requires_medical_certificate' => 'boolean',
            'auto_exclude_public_holidays' => 'boolean',
            'auto_exclude_weekends' => 'boolean',
            'gender_restriction' => LeaveTypeGenderRestriction::class,
            'min_service_days' => 'integer',
            'max_days_per_request' => 'integer',
            'max_days_per_year' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }
}
