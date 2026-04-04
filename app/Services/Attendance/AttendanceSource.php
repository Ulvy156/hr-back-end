<?php

namespace App\Services\Attendance;

final class AttendanceSource
{
    public const SelfService = 'self_service';
    public const Scan = 'scan';
    public const Manual = 'manual';
    public const Correction = 'correction';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::SelfService,
            self::Scan,
            self::Manual,
            self::Correction,
        ];
    }
}
