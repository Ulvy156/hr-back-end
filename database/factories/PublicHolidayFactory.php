<?php

namespace Database\Factories;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $holidayDate = CarbonImmutable::instance(fake()->dateTimeBetween('2026-01-01', '2026-12-31'));

        return [
            'name' => fake()->sentence(3),
            'holiday_date' => $holidayDate->toDateString(),
            'year' => $holidayDate->year,
            'country_code' => 'KH',
            'is_paid' => true,
            'source' => 'https://example.com/public-holidays',
            'metadata' => null,
        ];
    }
}
