<?php

return [
    'import_year' => (int) env('PUBLIC_HOLIDAYS_IMPORT_YEAR', now()->year),
    'country_code' => env('PUBLIC_HOLIDAYS_COUNTRY_CODE', 'KH'),
    'timeout_seconds' => (int) env('PUBLIC_HOLIDAYS_TIMEOUT_SECONDS', 15),
    'sources' => [
        'nager' => env('PUBLIC_HOLIDAYS_NAGER_URL', 'https://date.nager.at/api/v3/PublicHolidays/{year}/{country_code}'),
        'office_holidays' => env('PUBLIC_HOLIDAYS_FALLBACK_URL', 'https://www.officeholidays.com/countries/{country_slug}/{year}'),
    ],
];
