<?php

namespace App\Models;

use Database\Factories\EmployeeDependentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'name',
    'relationship',
    'date_of_birth',
    'is_active',
    'is_working',
    'is_student',
    'is_claimed_for_tax',
])]
class EmployeeDependent extends Model
{
    /** @use HasFactory<EmployeeDependentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
            'is_working' => 'boolean',
            'is_student' => 'boolean',
            'is_claimed_for_tax' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeClaimedForTax(Builder $query): Builder
    {
        return $query->where('is_claimed_for_tax', true);
    }
}
