<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable([
    'name',
    'rate_percentage',
    'min_salary',
    'max_salary',
    'is_active',
    'effective_from',
    'effective_to',
])]
class PayrollTaxRule extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_percentage' => 'decimal:2',
            'min_salary' => 'decimal:2',
            'max_salary' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function scopeActiveOn(Builder $query, Carbon|string $effectiveDate): Builder
    {
        $effectiveDate = $effectiveDate instanceof Carbon
            ? $effectiveDate->toDateString()
            : Carbon::parse($effectiveDate)->toDateString();

        return $query
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $effectiveDate)
            ->where(function (Builder $builder) use ($effectiveDate): void {
                $builder
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $effectiveDate);
            });
    }
}
