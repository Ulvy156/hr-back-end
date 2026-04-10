<?php

namespace App\Services\Leave;

final class LeaveRequestHalfDaySession
{
    public const Am = 'AM';

    public const Pm = 'PM';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::Am,
            self::Pm,
        ];
    }
}
