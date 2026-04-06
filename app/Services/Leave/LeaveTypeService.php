<?php

namespace App\Services\Leave;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Collection;

class LeaveTypeService
{
    public function listActive(): Collection
    {
        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
