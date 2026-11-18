<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeController
{
    private const RELATIONS = ['department', 'user', 'manager'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return EmployeeResource::collection(
            Employee::query()
                ->with(self::RELATIONS)
                // Team leaders only see their direct reports.
                ->unless($user->managesHr(), fn ($q) => $q->where('manager_id', $user->employee?->id ?? '-'))
                ->search($filters['search'] ?? null)
                ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate($filters['per_page'] ?? 25)
                ->withQueryString(),
        );
    }

    public function store(EmployeeRequest $request): EmployeeResource
    {
        $employee = Employee::create($request->validated());

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function destroy(Employee $employee): Response|JsonResponse
    {
        Gate::authorize('delete', $employee);

        if ($employee->reports()->exists()) {
            return response()->json([
                'message' => 'Other employees report to this person. Assign them a different manager first.',
            ], 409);
        }

        if ($employee->leaveRequests()->exists()) {
            return response()->json([
                'message' => 'This employee has leave history and cannot be deleted. Set the status to "terminated" instead.',
            ], 409);
        }

        $employee->delete();

        return response()->noContent();
    }
}
