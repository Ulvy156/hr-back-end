<?php

namespace App\Services\Overtime;

class OvertimeType
{
    /**
     * Standard workday overtime.
     */
    public const Normal = 'normal';

    /**
     * Weekend or rest-day overtime.
     */
    public const Weekend = 'weekend';

    /**
     * Public holiday overtime.
     */
    public const Holiday = 'holiday';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::Normal,
            self::Weekend,
            self::Holiday,
        ];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::Normal => 'Normal',
            self::Weekend => 'Weekend',
            self::Holiday => 'Holiday',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
