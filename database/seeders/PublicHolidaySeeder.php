<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    public function run(): void
    {
        collect([
            [
                'name' => 'Khmer New Year Eve',
                'holiday_date' => '2026-04-15',
                'year' => 2026,
                'country_code' => 'KH',
                'is_paid' => true,
                'source' => 'testing',
                'metadata' => ['testing_scenario' => 'leave-request-postman'],
            ],
            [
                'name' => 'Khmer New Year',
                'holiday_date' => '2026-04-16',
                'year' => 2026,
                'country_code' => 'KH',
                'is_paid' => true,
                'source' => 'testing',
                'metadata' => ['testing_scenario' => 'leave-request-postman'],
            ],
            [
                'name' => 'Independence Day',
                'holiday_date' => '2026-11-09',
                'year' => 2026,
                'country_code' => 'KH',
                'is_paid' => true,
                'source' => 'testing',
                'metadata' => ['testing_scenario' => 'public-holiday-api'],
            ],
        ])->each(function (array $holiday): void {
            PublicHoliday::query()->updateOrCreate(
                ['holiday_date' => $holiday['holiday_date']],
                $holiday,
            );
        });
    }
}
