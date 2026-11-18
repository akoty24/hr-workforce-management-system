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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Role-appropriate overview for the home screen. */
class DashboardController
{
    public function __invoke(Request $request, LeaveService $leave): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = now()->toDateString();

        if ($user->isAdmin()) {
            $onLeaveToday = LeaveRequest::query()
                ->with(['employee', 'leaveType'])
                ->where('status', LeaveStatus::Approved)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->get();

            $pending = LeaveRequest::query()
                ->with(['employee', 'leaveType'])
                ->where('status', LeaveStatus::Pending)
                ->orderBy('start_date')
                ->limit(5)
                ->get();

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

        return response()->json(['data' => [
            'role' => 'employee',
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'job_title' => $employee->job_title,
                'department' => $employee->department?->name,
            ] : null,
            'balances' => $employee ? $leave->balances($employee, now()->year) : [],
            'recent_requests' => $employee
                ? LeaveRequestResource::collection(
                    $employee->leaveRequests()->with(['employee', 'leaveType', 'decider'])->latest()->limit(5)->get(),
                )
                : [],
        ]]);
    }
}
