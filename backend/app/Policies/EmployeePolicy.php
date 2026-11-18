<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Employee records hold personal data:
 *  - admins manage all of them;
 *  - team leaders see (read-only) their direct reports;
 *  - everyone sees their own record.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->managesHr() || $user->isTeamLeader();
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->managesHr() || $employee->user_id === $user->id || $user->leads($employee);
    }

    public function create(User $user): bool
    {
        return $user->managesHr();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->managesHr();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->managesHr();
    }
}
