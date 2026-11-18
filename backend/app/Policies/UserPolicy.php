<?php

namespace App\Policies;

use App\Models\User;

/** Only company administrators manage user accounts. */
class UserPolicy
{
    /** HR may list accounts (to link them to employee records), not change them. */
    public function viewAny(User $user): bool
    {
        return $user->managesHr();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }
}
