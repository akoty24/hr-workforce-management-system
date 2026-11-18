<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the leave and hourly-permission controllers: who sees which
 * requests, and on whose behalf a request is filed.
 */
trait HandlesEmployeeRequests
{
    /**
     * Limit a query of employee-owned requests to what the user may list.
     * scope: mine = own requests; team = direct reports' requests.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function scopeToViewer(Builder $query, User $user, ?string $scope): void
    {
        if ($scope === 'mine' || (! $user->managesHr() && ! $user->isTeamLeader())) {
            // Employees only ever see their own requests.
            $query->whereHas('employee', fn ($q) => $q->where('user_id', $user->id));
        } elseif ($scope === 'team' || $user->isTeamLeader()) {
            $teamOnly = $scope === 'team';
            $ownEmployeeId = $user->employee?->id;
            // Team leaders: their direct reports (and their own, unless scope=team).
            // Grouped: an ungrouped orWhere would escape the whereHas join condition.
            $query->whereHas('employee', fn ($q) => $q->where(fn ($w) => $w
                ->where('manager_id', $ownEmployeeId ?? '-')
                ->unless($teamOnly, fn ($w) => $w->orWhere('user_id', $user->id))));
        }
    }

    /**
     * Admins may file on behalf of any employee (or themselves); everyone
     * else files only for the employee record linked to their account.
     */
    protected function resolveEmployee(User $user, ?string $employeeId): Employee
    {
        if ($user->managesHr() && $employeeId) {
            return Employee::query()->findOrFail($employeeId);
        }

        $own = $user->employee;

        if (! $own) {
            throw ValidationException::withMessages([
                'employee_id' => $user->managesHr()
                    ? 'Select the employee this request is for.'
                    : 'Your account is not linked to an employee record. Ask an administrator.',
            ]);
        }

        if (! $user->managesHr() && $employeeId && $employeeId !== $own->id) {
            abort(403, 'You can only make requests for yourself.');
        }

        return $own;
    }

    protected function notPending(): JsonResponse
    {
        return response()->json(['message' => 'Only pending requests can be changed.'], 409);
    }
}
