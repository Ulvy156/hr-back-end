<?php

namespace App\Services\Overtime;

class OvertimeRequestStatus
{
    /**
     * Status used while awaiting manager review.
     */
    public const Pending = 'pending';

    /**
     * Final approved status used for payroll inclusion.
     */
    public const Approved = 'approved';

    /**
     * Final rejected status.
     */
    public const Rejected = 'rejected';

    /**
     * Final cancelled status.
     */
    public const Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::Pending,
            self::Approved,
            self::Rejected,
            self::Cancelled,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function open(): array
    {
        return [
            self::Pending,
            self::Approved,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function reviewableByManager(): array
    {
        return [self::Pending];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
