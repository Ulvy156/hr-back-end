<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source_id', 'code', 'name_kh', 'name_en'])]
class Province extends Model
{
    public $timestamps = false;

    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class);
    }
}
