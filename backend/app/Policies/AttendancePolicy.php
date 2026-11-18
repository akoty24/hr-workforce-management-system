<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Employees see their own attendance, team leaders their team's, admins
 * and HR everyone's. Only admins and HR create or correct records.
 */
class AttendancePolicy
{
    public function view(User $user, Attendance $attendance): bool
    {
        return $user->managesHr()
            || $attendance->employee->user_id === $user->id
            || $user->leads($attendance->employee);
    }

    public function create(User $user): bool
    {
        return $user->managesHr();
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->managesHr();
    }
}
