<?php

namespace App\Services\Leave;

final class LeaveRequestStatus
{
    public const Pending = 'pending';

    public const ManagerApproved = 'manager_approved';

    public const HrApproved = 'hr_approved';

    public const Rejected = 'rejected';

    public const Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::Pending,
            self::ManagerApproved,
            self::HrApproved,
            self::Rejected,
            self::Cancelled,
        ];
    }
}
