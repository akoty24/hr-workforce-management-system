<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * - Admins see and decide all requests.
 * - Team leaders see and decide their direct reports' requests (final
 *   decision), but never their own.
 * - Everyone sees and cancels their own requests.
 */
class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->managesHr() || $this->owns($user, $request) || $user->leads($request->employee);
    }

    public function decide(User $user, LeaveRequest $request): bool
    {
        if ($this->owns($user, $request)) {
            return false; // nobody approves their own leave
        }

        return $user->managesHr() || $user->leads($request->employee);
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        return $user->managesHr() || $this->owns($user, $request);
    }

    private function owns(User $user, LeaveRequest $request): bool
    {
        return $request->employee->user_id === $user->id;
    }
}
