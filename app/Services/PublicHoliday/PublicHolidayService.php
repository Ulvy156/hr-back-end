<?php

namespace App\Services\PublicHoliday;

use App\Models\PublicHoliday;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PublicHolidayService
{
    /**
     * @param  array{year?: int|string|null}  $filters
     * @return Collection<int, PublicHoliday>
     */
    public function listActive(array $filters = []): Collection
    {
        return $this->baseQuery()
            ->when(
                filled($filters['year'] ?? null),
                fn (Builder $query): Builder => $query->where('year', (int) $filters['year'])
            )
            ->orderBy('holiday_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function holidayDatesBetween(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        return $this->baseQuery()
            ->whereBetween('holiday_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderBy('holiday_date')
            ->pluck('holiday_date')
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
            ->all();
    }

    private function baseQuery(): Builder
    {
        return PublicHoliday::query()
            ->where('country_code', $this->countryCode())
            ->whereNotNull('name')
            ->whereNotNull('holiday_date')
            ->whereNotNull('year');
    }

    private function countryCode(): string
    {
        return (string) config('public_holidays.country_code', 'KH');
    }
}
