<?php

namespace App\Models;

use App\EmployeeAddressType;
use Database\Factories\EmployeeAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'address_type',
    'province_id',
    'district_id',
    'commune_id',
    'village_id',
    'address_line',
    'street',
    'house_no',
    'postal_code',
    'note',
    'is_primary',
])]
class EmployeeAddress extends Model
{
    /** @use HasFactory<EmployeeAddressFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address_type' => EmployeeAddressType::class,
            'is_primary' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }
}
