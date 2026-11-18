<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeaveRequestResource;
use App\Leave\LeaveService;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use App\Models\User;
use App\Tenancy\Validation\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveRequestController
{
    private const RELATIONS = ['employee', 'leaveType', 'decider'];

    public function __construct(private readonly LeaveService $leave) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LeaveStatus::class)],
            'employee_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $query = LeaveRequest::query()->with(self::RELATIONS);

        if (! $user->isAdmin()) {
            // Non-admins only ever see their own requests.
            $query->whereHas('employee', fn ($q) => $q->where('user_id', $user->id));
        } elseif ($filters['employee_id'] ?? null) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return LeaveRequestResource::collection(
            $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('start_date')
                ->orderByDesc('created_at')
                ->paginate(50)
                ->withQueryString(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'employee_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'leave_type_id' => ['required', 'uuid', TenantRule::exists('leave_types')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = $this->resolveEmployee($user, $data['employee_id'] ?? null);
        $start = CarbonImmutable::parse($data['start_date']);

        if (! $user->isAdmin() && $start->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['start_date' => 'Leave cannot start in the past.']);
        }

        $leaveRequest = $this->leave->submit(
            $employee,
            LeaveType::query()->findOrFail($data['leave_type_id']),
            $start,
            CarbonImmutable::parse($data['end_date']),
            filled($data['reason'] ?? null) ? trim($data['reason']) : null,
        );

        return (new LeaveRequestResource($leaveRequest->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('view', $leaveRequest);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource|JsonResponse
    {
        return $this->decide($request, $leaveRequest, LeaveStatus::Approved);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource|JsonResponse
    {
        return $this->decide($request, $leaveRequest, LeaveStatus::Rejected);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource|JsonResponse
    {
        Gate::authorize('cancel', $leaveRequest);

        if (! $leaveRequest->isPending()) {
            return $this->notPending();
        }

        $this->leave->decide($leaveRequest, $request->user(), LeaveStatus::Cancelled, null);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }

    private function decide(Request $request, LeaveRequest $leaveRequest, LeaveStatus $status): LeaveRequestResource|JsonResponse
    {
        Gate::authorize('decide', $leaveRequest);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        if (! $leaveRequest->isPending()) {
            return $this->notPending();
        }

        $this->leave->decide($leaveRequest, $request->user(), $status, filled($data['note'] ?? null) ? trim($data['note']) : null);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }

    /**
     * Admins may file on behalf of any employee (or themselves); everyone
     * else files only for the employee record linked to their account.
     */
    private function resolveEmployee(User $user, ?string $employeeId): Employee
    {
        if ($user->isAdmin() && $employeeId) {
            return Employee::query()->findOrFail($employeeId);
        }

        $own = $user->employee;

        if (! $own) {
            throw ValidationException::withMessages([
                'employee_id' => $user->isAdmin()
                    ? 'Select the employee this request is for.'
                    : 'Your account is not linked to an employee record. Ask an administrator.',
            ]);
        }

        if (! $user->isAdmin() && $employeeId && $employeeId !== $own->id) {
            abort(403, 'You can only request leave for yourself.');
        }

        return $own;
    }

    private function notPending(): JsonResponse
    {
        return response()->json(['message' => 'Only pending requests can be changed.'], 409);
    }
}
