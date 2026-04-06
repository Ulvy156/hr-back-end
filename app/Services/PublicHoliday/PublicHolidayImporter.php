<?php

namespace App\Services\PublicHoliday;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicHolidayImporter
{
    /**
     * @return int Number of records processed.
     */
    public function import(int $year): int
    {
        try {
            $holidays = $this->fetchFromNager($year);

            if ($holidays === []) {
                $holidays = $this->fetchFromOfficeHolidays($year);
            }

            if ($holidays === []) {
                Log::warning('No Cambodia public holidays could be imported.', [
                    'year' => $year,
                ]);

                return 0;
            }

            foreach ($holidays as $holiday) {
                PublicHoliday::query()->updateOrCreate(
                    ['holiday_date' => $holiday['holiday_date']],
                    Arr::except($holiday, ['holiday_date']),
                );
            }

            return count($holidays);
        } catch (Throwable $throwable) {
            Log::error('Cambodia public holiday import failed unexpectedly.', [
                'year' => $year,
                'message' => $throwable->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @return list<array{name: string, holiday_date: string, year: int, country_code: string, is_paid: bool, source: string, metadata: array<string, mixed>}>
     */
    private function fetchFromNager(int $year): array
    {
        $sourceUrl = $this->nagerUrl($year);
        $response = Http::timeout($this->timeoutSeconds())
            ->acceptJson()
            ->get($sourceUrl);

        if ($response->status() === 204) {
            Log::warning('Nager returned no Cambodia public holiday data.', [
                'year' => $year,
                'source' => $sourceUrl,
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('Failed to fetch Cambodia public holidays from Nager.', [
                'year' => $year,
                'source' => $sourceUrl,
                'status' => $response->status(),
            ]);

            return [];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::error('Unexpected Nager public holiday payload.', [
                'year' => $year,
                'source' => $sourceUrl,
            ]);

            return [];
        }

        return collect($payload)
            ->filter(fn (mixed $holiday): bool => is_array($holiday) && filled($holiday['date'] ?? null) && filled($holiday['name'] ?? null))
            ->map(fn (array $holiday): array => $this->mapNagerHoliday($holiday, $sourceUrl))
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, holiday_date: string, year: int, country_code: string, is_paid: bool, source: string, metadata: array<string, mixed>}>
     */
    private function fetchFromOfficeHolidays(int $year): array
    {
        $sourceUrl = $this->officeHolidaysUrl($year);
        $response = Http::timeout($this->timeoutSeconds())->get($sourceUrl);

        if (! $response->successful()) {
            Log::error('Failed to fetch Cambodia public holidays from OfficeHolidays fallback.', [
                'year' => $year,
                'source' => $sourceUrl,
                'status' => $response->status(),
            ]);

            return [];
        }

        $rows = $this->parseOfficeHolidaysRows($response->body(), $sourceUrl);

        if ($rows === []) {
            Log::error('OfficeHolidays fallback did not return any parsable Cambodia public holidays.', [
                'year' => $year,
                'source' => $sourceUrl,
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $holiday
     * @return array{name: string, holiday_date: string, year: int, country_code: string, is_paid: bool, source: string, metadata: array<string, mixed>}
     */
    private function mapNagerHoliday(array $holiday, string $sourceUrl): array
    {
        $holidayDate = CarbonImmutable::parse((string) $holiday['date'])->toDateString();

        return [
            'name' => (string) $holiday['name'],
            'holiday_date' => $holidayDate,
            'year' => CarbonImmutable::parse($holidayDate)->year,
            'country_code' => $this->countryCode(),
            'is_paid' => true,
            'source' => $sourceUrl,
            'metadata' => [
                'source_provider' => 'nager',
                'local_name' => $holiday['localName'] ?? null,
                'global' => $holiday['global'] ?? null,
                'launch_year' => $holiday['launchYear'] ?? null,
                'types' => array_values(array_filter((array) ($holiday['types'] ?? []), 'is_string')),
            ],
        ];
    }

    /**
     * @return list<array{name: string, holiday_date: string, year: int, country_code: string, is_paid: bool, source: string, metadata: array<string, mixed>}>
     */
    private function parseOfficeHolidaysRows(string $html, string $sourceUrl): array
    {
        preg_match_all(
            '/<tr[^>]*>\s*<td>[^<]*<\/td>\s*<td[^>]*><time[^>]*datetime="(?<date>[^"]+)">[^<]*<\/time><\/td>\s*<td><a[^>]*>(?<name>.*?)<\/a><\/td>\s*<td[^>]*class="comments"[^>]*>(?<type>.*?)<\/td>\s*<td[^>]*class="hide-ipadmobile"[^>]*>(?<comments>.*?)<\/td>\s*<\/tr>/si',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->map(function (array $match) use ($sourceUrl): array {
                $holidayDate = CarbonImmutable::parse((string) $match['date'])->toDateString();

                return [
                    'name' => $this->cleanHtmlValue((string) $match['name']),
                    'holiday_date' => $holidayDate,
                    'year' => CarbonImmutable::parse($holidayDate)->year,
                    'country_code' => $this->countryCode(),
                    'is_paid' => true,
                    'source' => $sourceUrl,
                    'metadata' => [
                        'source_provider' => 'officeholidays',
                        'type' => $this->cleanHtmlValue((string) $match['type']),
                        'comments' => $this->cleanHtmlValue((string) $match['comments']),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function cleanHtmlValue(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function countryCode(): string
    {
        return (string) config('public_holidays.country_code', 'KH');
    }

    private function timeoutSeconds(): int
    {
        return max((int) config('public_holidays.timeout_seconds', 15), 1);
    }

    private function nagerUrl(int $year): string
    {
        return str_replace(
            ['{year}', '{country_code}'],
            [(string) $year, $this->countryCode()],
            (string) config('public_holidays.sources.nager', 'https://date.nager.at/api/v3/PublicHolidays/{year}/{country_code}'),
        );
    }

    private function officeHolidaysUrl(int $year): string
    {
        return str_replace(
            ['{year}', '{country_slug}'],
            [(string) $year, 'cambodia'],
            (string) config('public_holidays.sources.office_holidays', 'https://www.officeholidays.com/countries/{country_slug}/{year}'),
        );
    }
}
