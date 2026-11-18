<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Employee records hold personal data: administrators manage all of them;
 * other users can only view the record linked to their own account.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->isAdmin() || $employee->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->isAdmin();
    }
}
