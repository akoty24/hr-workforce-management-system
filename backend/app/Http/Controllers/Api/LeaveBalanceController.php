<?php

namespace App\Http\Controllers\Api;

use App\Leave\LeaveService;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LeaveBalanceController
{
    public function __invoke(Request $request, LeaveService $leave): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'uuid'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $employee = isset($data['employee_id'])
            ? Employee::query()->findOrFail($data['employee_id'])
            : $user->employee;

        if (! $employee) {
            return response()->json(['data' => [], 'message' => 'No employee record is linked to this account.']);
        }

        Gate::authorize('view', $employee);

        return response()->json([
            'data' => $leave->balances($employee, (int) ($data['year'] ?? now()->year)),
        ]);
    }
}
