<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

/**
 * Everyone sees the holiday calendar; admins and HR maintain it.
 */
class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->managesHr();
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->managesHr();
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->managesHr();
    }
}
