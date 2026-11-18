<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\ShortLeave;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who is e-mailed about a request.
 */
final class RequestNotifier
{
    /** New request -> the employee's team leader, else all active admins and HR. Never the requester. */
    public function submitted(LeaveRequest|ShortLeave $request): void
    {
        $employee = $request->employee;
        $leader = $employee->manager?->user;

        $recipients = $leader && $leader->is_active && $leader->id !== $employee->user_id
            ? collect([$leader])
            : User::query()
                ->whereIn('role', [UserRole::Admin, UserRole::Hr])
                ->where('is_active', true)
                ->when($employee->user_id, fn ($q, $id) => $q->whereKeyNot($id))
                ->get();

        Notification::send($recipients, new RequestSubmitted($request));
    }

    /** Approved / rejected -> the employee (their account e-mail, else the employee record e-mail). */
    public function decided(LeaveRequest|ShortLeave $request): void
    {
        $employee = $request->employee;
        $user = $employee->user;

        if ($user && $user->is_active) {
            $user->notify(new RequestDecided($request));
        } elseif ($employee->email) {
            Notification::route('mail', $employee->email)->notify(new RequestDecided($request));
        }
    }
}
