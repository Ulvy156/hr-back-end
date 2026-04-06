<?php

namespace Database\Seeders;

use App\Services\PublicHoliday\PublicHolidayImporter;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PublicHolidayImporter::class)->import(
            (int) config('public_holidays.import_year', now()->year)
        );
    }
}
