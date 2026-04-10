<?php

namespace App\Services\Leave;

final class LeaveRequestDurationType
{
    public const FullDay = 'full_day';

    public const HalfDay = 'half_day';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::FullDay,
            self::HalfDay,
        ];
    }
}
