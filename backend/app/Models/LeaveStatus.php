<?php

namespace App\Models;

enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Statuses that occupy calendar time and consume balance.
     *
     * @return list<string>
     */
    public static function blocking(): array
    {
        return [self::Pending->value, self::Approved->value];
    }
}
