<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Tenant isolation is already enforced before policies run (tenant scope +
 * tenant.auth); policies only decide what a user may do inside their company.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Department $department): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->managesHr();
    }

    public function update(User $user, Department $department): bool
    {
        return $user->managesHr();
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->managesHr();
    }
}
