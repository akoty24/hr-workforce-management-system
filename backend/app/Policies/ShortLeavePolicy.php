<?php

namespace App\Policies;

use App\Models\ShortLeave;
use App\Models\User;

/**
 * Same rules as leave: admins and the direct team leader decide (final),
 * never on their own; owners and admins cancel pending ones.
 */
class ShortLeavePolicy
{
    public function view(User $user, ShortLeave $shortLeave): bool
    {
        return $user->isAdmin() || $this->owns($user, $shortLeave) || $user->leads($shortLeave->employee);
    }

    public function decide(User $user, ShortLeave $shortLeave): bool
    {
        if ($this->owns($user, $shortLeave)) {
            return false;
        }

        return $user->isAdmin() || $user->leads($shortLeave->employee);
    }

    public function cancel(User $user, ShortLeave $shortLeave): bool
    {
        return $user->isAdmin() || $this->owns($user, $shortLeave);
    }

    private function owns(User $user, ShortLeave $shortLeave): bool
    {
        return $shortLeave->employee->user_id === $user->id;
    }
}
