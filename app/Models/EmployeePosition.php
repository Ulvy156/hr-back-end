<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['employee_id', 'position_id', 'base_salary', 'start_date', 'end_date'])]
class EmployeePosition extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeActiveOn(Builder $query, Carbon|string $referenceDate): Builder
    {
        $referenceDate = $referenceDate instanceof Carbon
            ? $referenceDate->toDateString()
            : $referenceDate;

        return $query
            ->whereDate('start_date', '<=', $referenceDate)
            ->where(function (Builder $query) use ($referenceDate): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $referenceDate);
            });
    }
}
