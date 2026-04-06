<?php

namespace App\Services\Employee;

use App\Models\Position;
use Illuminate\Database\Eloquent\Collection;

class PositionService
{
    /**
     * @param  array{search?: string|null}  $filters
     */
    public function list(array $filters = []): Collection
    {
        return Position::query()
            ->when(
                filled($filters['search'] ?? null),
                function ($query) use ($filters): void {
                    $search = mb_strtolower(trim((string) $filters['search']));
                    $query->whereRaw('LOWER(title) LIKE ?', ['%'.$search.'%']);
                }
            )
            ->orderBy('title')
            ->get();
    }
}
