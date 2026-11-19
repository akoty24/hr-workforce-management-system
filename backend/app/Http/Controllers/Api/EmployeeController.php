<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Attendance;
use App\Models\EmployeeDocument;
use App\Models\Payslip;
use App\Models\Salary;
use App\Models\ShortLeave;
use App\Models\User;
use App\Tenancy\DefaultPassword;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeController
{
    private const RELATIONS = ['department', 'user', 'manager'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantFileStorage $files,
    ) {}

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
        $employee = DB::transaction(function () use ($request) {
            $employee = Employee::create($request->safe()->except(['account', 'new_password']));
            $this->createAccount($employee, $request);

            return $employee;
        });

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        DB::transaction(function () use ($request, $employee) {
            $employee->update($request->safe()->except(['account', 'new_password']));
            $this->createAccount($employee, $request);

            if ($request->filled('new_password')) {
                $employee->user->update(['password' => $request->validated('new_password'), 'must_change_password' => true]);
            }
        });

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

        // Any other HR history (attendance, permissions, salaries, payslips) is kept too.
        $hasHistory = ShortLeave::query()->where('employee_id', $employee->id)->exists()
            || Attendance::query()->where('employee_id', $employee->id)->exists()
            || Salary::query()->where('employee_id', $employee->id)->exists()
            || Payslip::query()->where('employee_id', $employee->id)->exists();
        if ($hasHistory) {
            return response()->json([
                'message' => 'This employee has attendance, salary or payroll history and cannot be deleted. Set the status to "terminated" instead.',
            ], 409);
        }

        // Documents go with the record (files included).
        DB::transaction(function () use ($employee) {
            foreach (EmployeeDocument::query()->where('employee_id', $employee->id)->get() as $document) {
                $this->files->delete($document->path);
                $document->delete();
            }
            $employee->delete();
        });

        return response()->noContent();
    }

    /** Create and link a sign-in account when the form asked for one. */
    private function createAccount(Employee $employee, EmployeeRequest $request): void
    {
        $account = $request->account();

        if (! $account) {
            return;
        }

        // Empty password = the company default; either way the employee
        // chooses their own at first sign-in.
        $user = User::create([
            'name' => $employee->full_name,
            'email' => $employee->email,
            'role' => $account['role'],
            'password' => filled($account['password'] ?? null) ? $account['password'] : DefaultPassword::for($this->context->current()),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $employee->update(['user_id' => $user->id]);
    }
}
