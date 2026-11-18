<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Admins see and decide all requests; other users see and cancel only the
 * requests of the employee record linked to their own account.
 */
class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->isAdmin() || $this->owns($user, $request);
    }

    public function decide(User $user, LeaveRequest $request): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        return $user->isAdmin() || $this->owns($user, $request);
    }

    private function owns(User $user, LeaveRequest $request): bool
    {
        return $request->employee()->where('user_id', $user->id)->exists();
    }
}
