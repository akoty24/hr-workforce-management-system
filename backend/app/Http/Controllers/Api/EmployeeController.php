<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return EmployeeResource::collection(
            Employee::query()
                ->with(['department', 'user'])
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

        return new EmployeeResource($employee->load(['department', 'user']));
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load(['department', 'user']));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        return new EmployeeResource($employee->load(['department', 'user']));
    }

    public function destroy(Employee $employee): Response|JsonResponse
    {
        Gate::authorize('delete', $employee);

        if ($employee->leaveRequests()->exists()) {
            return response()->json([
                'message' => 'This employee has leave history and cannot be deleted. Set the status to "terminated" instead.',
            ], 409);
        }

        $employee->delete();

        return response()->noContent();
    }
}
