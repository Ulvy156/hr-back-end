<?php

namespace App\Models;

use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'holiday_date',
    'year',
    'country_code',
    'is_paid',
    'source',
    'metadata',
])]
class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_paid' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
