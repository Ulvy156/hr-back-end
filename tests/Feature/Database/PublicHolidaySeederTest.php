<?php

use App\Models\PublicHoliday;
use App\Services\PublicHoliday\PublicHolidayImporter;
use Database\Seeders\PublicHolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('public_holidays.import_year', 2026);
    Http::preventStrayRequests();
});

it('imports cambodia public holidays from nager and avoids duplicates on reseed', function () {
    PublicHoliday::factory()->create([
        'name' => 'Old Holiday Name',
        'holiday_date' => '2026-01-01',
        'year' => 2026,
        'country_code' => 'KH',
        'is_paid' => false,
        'source' => 'https://old-source.test',
        'metadata' => ['source_provider' => 'legacy'],
    ]);

    Http::fake([
        'https://date.nager.at/api/v3/PublicHolidays/2026/KH' => Http::response([
            [
                'date' => '2026-01-01',
                'localName' => 'International New Year',
                'name' => "New Year's Day",
                'countryCode' => 'KH',
                'global' => true,
                'launchYear' => null,
                'types' => ['Public'],
            ],
            [
                'date' => '2026-01-07',
                'localName' => 'Victory over Genocide Day',
                'name' => 'Victory over Genocide Day',
                'countryCode' => 'KH',
                'global' => true,
                'launchYear' => null,
                'types' => ['Public'],
            ],
        ], 200),
        'https://www.officeholidays.com/*' => Http::response('', 500),
    ]);

    expect(app(PublicHolidayImporter::class)->import(2026))->toBe(2);

    $holiday = PublicHoliday::query()->whereDate('holiday_date', '2026-01-01')->firstOrFail();

    expect(PublicHoliday::query()->count())->toBe(2)
        ->and($holiday->name)->toBe("New Year's Day")
        ->and($holiday->year)->toBe(2026)
        ->and($holiday->country_code)->toBe('KH')
        ->and($holiday->is_paid)->toBeTrue()
        ->and($holiday->source)->toBe('https://date.nager.at/api/v3/PublicHolidays/2026/KH')
        ->and($holiday->metadata)->toMatchArray([
            'source_provider' => 'nager',
            'local_name' => 'International New Year',
            'global' => true,
            'types' => ['Public'],
        ]);
});

it('falls back to office holidays when nager has no cambodia data', function () {
    Http::fake([
        'https://date.nager.at/api/v3/PublicHolidays/2026/KH' => Http::response('', 204),
        'https://www.officeholidays.com/countries/cambodia/2026' => Http::response(<<<'HTML'
            <table>
                <tbody>
                    <tr class="country-past">
                        <td>Thursday</td>
                        <td style="white-space:nowrap;"><time itemprop="startDate" datetime="2026-01-01">Jan 01</time></td>
                        <td><a class="country-listing" title="Cambodia" href="https://www.officeholidays.com/holidays/cambodia/international-new-years-day">New Year&#039;s Day</a></td>
                        <td style="white-space:nowrap;" class="comments">National Holiday</td>
                        <td class="hide-ipadmobile"></td>
                    </tr>
                    <tr class="country-past">
                        <td>Wednesday</td>
                        <td style="white-space:nowrap;"><time itemprop="startDate" datetime="2026-01-07">Jan 07</time></td>
                        <td><a class="country-listing" title="Cambodia" href="https://www.officeholidays.com/holidays/cambodia/victory-over-genocide-day">Victory over Genocide Day</a></td>
                        <td style="white-space:nowrap;" class="comments">National Holiday</td>
                        <td class="hide-ipadmobile"></td>
                    </tr>
                </tbody>
            </table>
        HTML, 200),
    ]);

    expect(app(PublicHolidayImporter::class)->import(2026))->toBe(2);

    $holiday = PublicHoliday::query()->whereDate('holiday_date', '2026-01-07')->firstOrFail();

    expect(PublicHoliday::query()->count())->toBe(2)
        ->and($holiday->name)->toBe('Victory over Genocide Day')
        ->and($holiday->source)->toBe('https://www.officeholidays.com/countries/cambodia/2026')
        ->and($holiday->metadata)->toMatchArray([
            'source_provider' => 'officeholidays',
            'type' => 'National Holiday',
            'comments' => '',
        ]);
});

it('logs failures and keeps seeding resilient when all sources fail', function () {
    Log::spy();

    Http::fake([
        'https://date.nager.at/api/v3/PublicHolidays/2026/KH' => Http::response('', 500),
        'https://www.officeholidays.com/countries/cambodia/2026' => Http::response('', 500),
    ]);

    expect(app(PublicHolidayImporter::class)->import(2026))->toBe(0);

    expect(PublicHoliday::query()->count())->toBe(0);

    Log::shouldHaveReceived('error')->atLeast()->once();
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('delegates seeding to the public holiday importer using the configured year', function () {
    $this->mock(PublicHolidayImporter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('import')
            ->once()
            ->with(2026)
            ->andReturn(0);
    });

    $this->seed(PublicHolidaySeeder::class);
});
