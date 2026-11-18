<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesEmployeeRequests;
use App\Http\Resources\ShortLeaveResource;
use App\Leave\ShortLeaveService;
use App\Models\LeaveStatus;
use App\Models\ShortLeave;
use App\Models\User;
use App\Notifications\RequestNotifier;
use App\Tenancy\Validation\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hourly permissions ("short leaves"), approved like leave requests.
 */
class ShortLeaveController
{
    use HandlesEmployeeRequests;

    private const RELATIONS = ['employee', 'decider'];

    public function __construct(
        private readonly ShortLeaveService $shortLeaves,
        private readonly RequestNotifier $notifier,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LeaveStatus::class)],
            'employee_id' => ['nullable', 'uuid'],
            'scope' => ['nullable', Rule::in(['mine', 'team'])],
        ]);

        $query = ShortLeave::query()->with(self::RELATIONS);
        $this->scopeToViewer($query, $request->user(), $filters['scope'] ?? null);

        return ShortLeaveResource::collection(
            $query->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('date')
                ->orderByDesc('start_time')
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
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = $this->resolveEmployee($user, $data['employee_id'] ?? null);
        $date = CarbonImmutable::parse($data['date']);

        if (! $user->managesHr() && $date->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => 'The date cannot be in the past.']);
        }

        $shortLeave = $this->shortLeaves->submit(
            $employee,
            $date,
            $data['start_time'],
            $data['end_time'],
            filled($data['reason'] ?? null) ? trim($data['reason']) : null,
        );

        $this->notifier->submitted($shortLeave);

        return (new ShortLeaveResource($shortLeave->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(ShortLeave $shortLeave): ShortLeaveResource
    {
        Gate::authorize('view', $shortLeave);

        return new ShortLeaveResource($shortLeave->load(self::RELATIONS));
    }

    public function approve(Request $request, ShortLeave $shortLeave): ShortLeaveResource|JsonResponse
    {
        return $this->decide($request, $shortLeave, LeaveStatus::Approved);
    }

    public function reject(Request $request, ShortLeave $shortLeave): ShortLeaveResource|JsonResponse
    {
        return $this->decide($request, $shortLeave, LeaveStatus::Rejected);
    }

    public function cancel(Request $request, ShortLeave $shortLeave): ShortLeaveResource|JsonResponse
    {
        Gate::authorize('cancel', $shortLeave);

        if (! $shortLeave->isPending()) {
            return $this->notPending();
        }

        $this->shortLeaves->decide($shortLeave, $request->user(), LeaveStatus::Cancelled, null);

        return new ShortLeaveResource($shortLeave->load(self::RELATIONS));
    }

    private function decide(Request $request, ShortLeave $shortLeave, LeaveStatus $status): ShortLeaveResource|JsonResponse
    {
        Gate::authorize('decide', $shortLeave);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        if (! $shortLeave->isPending()) {
            return $this->notPending();
        }

        $this->shortLeaves->decide($shortLeave, $request->user(), $status, filled($data['note'] ?? null) ? trim($data['note']) : null);
        $this->notifier->decided($shortLeave->load(self::RELATIONS));

        return new ShortLeaveResource($shortLeave->load(self::RELATIONS));
    }
}
