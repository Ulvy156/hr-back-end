<?php

namespace App\Services\Overtime;

class OvertimeApprovalStage
{
    /**
     * Stage awaiting manager action.
     */
    public const ManagerReview = 'manager_review';

    /**
     * Final stage after full approval.
     */
    public const Completed = 'completed';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ManagerReview,
            self::Completed,
        ];
    }

    public static function label(string $stage): string
    {
        return match ($stage) {
            self::ManagerReview => 'Manager Review',
            self::Completed => 'Completed',
            default => ucfirst(str_replace('_', ' ', $stage)),
        };
    }
}
