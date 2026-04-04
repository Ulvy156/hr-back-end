<?php

namespace App\Services\Attendance;

final class AttendanceCorrectionRequestStatus
{
    public const Pending = 'pending';
    public const Approved = 'approved';
    public const Rejected = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::Pending,
            self::Approved,
            self::Rejected,
        ];
    }
}
