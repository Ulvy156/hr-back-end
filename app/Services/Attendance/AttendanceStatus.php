<?php

namespace App\Services\Attendance;

final class AttendanceStatus
{
    public const NotCheckedIn = 'not_checked_in';
    public const CheckedIn = 'checked_in';
    public const CheckedOut = 'checked_out';
    public const Present = 'present';
    public const Late = 'late';
    public const Absent = 'absent';
    public const Corrected = 'corrected';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::NotCheckedIn,
            self::CheckedIn,
            self::CheckedOut,
            self::Present,
            self::Late,
            self::Absent,
            self::Corrected,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function persisted(): array
    {
        return [
            self::CheckedIn,
            self::Present,
            self::Late,
            self::Absent,
            self::Corrected,
        ];
    }
}
