<?php

namespace App\Services\Employee;

use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LocationService
{
    /**
     * @param  array{search?: string|null}  $filters
     */
    public function provinces(array $filters = []): Collection
    {
        return Province::query()
            ->tap(fn (Builder $query): Builder => $this->applySearch($query, $filters['search'] ?? null))
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array{province_id?: int|string|null, search?: string|null}  $filters
     */
    public function districts(array $filters = []): Collection
    {
        return District::query()
            ->when(
                isset($filters['province_id']),
                fn (Builder $query): Builder => $query->where('province_id', $filters['province_id'])
            )
            ->tap(fn (Builder $query): Builder => $this->applySearch($query, $filters['search'] ?? null))
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array{district_id?: int|string|null, search?: string|null}  $filters
     */
    public function communes(array $filters = []): Collection
    {
        return Commune::query()
            ->when(
                isset($filters['district_id']),
                fn (Builder $query): Builder => $query->where('district_id', $filters['district_id'])
            )
            ->tap(fn (Builder $query): Builder => $this->applySearch($query, $filters['search'] ?? null))
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array{commune_id?: int|string|null, search?: string|null}  $filters
     */
    public function villages(array $filters = []): Collection
    {
        return Village::query()
            ->when(
                isset($filters['commune_id']),
                fn (Builder $query): Builder => $query->where('commune_id', $filters['commune_id'])
            )
            ->tap(fn (Builder $query): Builder => $this->applySearch($query, $filters['search'] ?? null))
            ->orderBy('code')
            ->get();
    }

    private function applySearch(Builder $query, mixed $search): Builder
    {
        if (! filled($search)) {
            return $query;
        }

        $normalizedSearch = mb_strtolower(trim((string) $search));

        return $query->where(function (Builder $locationQuery) use ($normalizedSearch): void {
            $locationQuery
                ->whereRaw('LOWER(code) LIKE ?', ['%'.$normalizedSearch.'%'])
                ->orWhereRaw('LOWER(name_en) LIKE ?', ['%'.$normalizedSearch.'%'])
                ->orWhereRaw('LOWER(name_kh) LIKE ?', ['%'.$normalizedSearch.'%']);
        });
    }
}
