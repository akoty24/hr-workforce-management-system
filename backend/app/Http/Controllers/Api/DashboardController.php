<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeaveRequestResource;
use App\Leave\LeaveService;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Role-appropriate overview for the home screen. */
class DashboardController
{
    private const RELATIONS = ['employee', 'leaveType', 'decider'];

    public function __invoke(Request $request, LeaveService $leave): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isAdmin()) {
            $onLeaveToday = $this->onLeaveToday(LeaveRequest::query())->get();
            $pending = $this->pending(LeaveRequest::query())->limit(5)->get();

            return response()->json(['data' => [
                'role' => 'admin',
                'stats' => [
                    'employees_total' => Employee::query()->count(),
                    'employees_active' => Employee::query()->where('status', EmployeeStatus::Active)->count(),
                    'departments' => Department::query()->count(),
                    'pending_requests' => LeaveRequest::query()->where('status', LeaveStatus::Pending)->count(),
                    'on_leave_today' => $onLeaveToday->count(),
                ],
                'pending_requests' => LeaveRequestResource::collection($pending),
                'on_leave_today' => LeaveRequestResource::collection($onLeaveToday),
            ]]);
        }

        $employee = $user->employee;

        $data = [
            'role' => $user->role->value,
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'job_title' => $employee->job_title,
                'department' => $employee->department?->name,
                'manager' => $employee->manager?->full_name,
            ] : null,
            'balances' => $employee ? $leave->balances($employee, now()->year) : [],
            'recent_requests' => $employee
                ? LeaveRequestResource::collection($employee->leaveRequests()->with(self::RELATIONS)->latest()->limit(5)->get())
                : [],
        ];

        if ($user->isTeamLeader() && $employee) {
            $team = fn () => LeaveRequest::query()->whereHas('employee', fn ($q) => $q->where('manager_id', $employee->id));

            $data['team'] = [
                'size' => $employee->reports()->count(),
                'pending_requests' => LeaveRequestResource::collection($this->pending($team())->get()),
                'on_leave_today' => LeaveRequestResource::collection($this->onLeaveToday($team())->get()),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private function pending(Builder $query): Builder
    {
        return $query->with(self::RELATIONS)->where('status', LeaveStatus::Pending)->orderBy('start_date');
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private function onLeaveToday(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->with(self::RELATIONS)
            ->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }
}
