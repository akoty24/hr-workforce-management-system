<?php

namespace App\Http\Controllers\Api;

use App\Attendance\AttendanceService;
use App\Http\Controllers\Api\Concerns\HandlesEmployeeRequests;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Settings\CompanySettings;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController
{
    use HandlesEmployeeRequests;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly CompanySettings $settings,
    ) {}

    /** Today's record of the signed-in employee, plus the schedule. */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request->user());

        return response()->json(['data' => [
            'date' => $this->attendance->now()->toDateString(),
            'record' => ($record = $this->attendance->today($employee)) ? $this->present($record) : null,
            'schedule' => $this->settings->all(),
        ]]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $record = $this->attendance->checkIn($this->ownEmployee($request->user()), $this->location($request));

        return response()->json(['data' => $this->present($record)], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $record = $this->attendance->checkOut($this->ownEmployee($request->user()), $this->location($request));

        return response()->json(['data' => $this->present($record)]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'uuid'],
            'scope' => ['nullable', Rule::in(['mine', 'team'])],
        ]);

        $today = $this->attendance->now();
        $from = $filters['from'] ?? $today->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $today->toDateString();

        $query = Attendance::query()->with(['employee', 'editor']);
        $this->scopeToViewer($query, $request->user(), $filters['scope'] ?? null);

        $records = $query
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->orderByDesc('date')
            ->orderBy('check_in_at')
            ->limit(1000)
            ->get();

        return response()->json([
            'data' => $records->map($this->present(...))->values(),
            'meta' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Attendance::class);

        $data = $this->validatedRecord($request, true);

        $record = $this->attendance->record(
            Employee::query()->findOrFail($data['employee_id']),
            $data['date'], $data['check_in'], $data['check_out'] ?? null, $data['note'] ?? null, $request->user(),
        );

        return response()->json(['data' => $this->present($record->load(['employee', 'editor']))], 201);
    }

    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        Gate::authorize('update', $attendance);

        $data = $this->validatedRecord($request, false);

        $record = $this->attendance->record(
            $attendance->employee,
            $attendance->date->toDateString(), $data['check_in'], $data['check_out'] ?? null, $data['note'] ?? null,
            $request->user(), $attendance,
        );

        return response()->json(['data' => $this->present($record->load(['employee', 'editor']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRecord(Request $request, bool $creating): array
    {
        return $request->validate(array_filter([
            'employee_id' => $creating ? ['required', 'uuid', TenantRule::exists('employees')] : null,
            'date' => $creating ? ['required', 'date_format:Y-m-d', 'before_or_equal:today'] : null,
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]));
    }

    /**
     * @return array{latitude: float|null, longitude: float|null, accuracy: float|null}
     */
    private function location(Request $request): array
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        return [
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'accuracy' => isset($data['accuracy']) ? (float) $data['accuracy'] : null,
        ];
    }

    private function ownEmployee(User $user): Employee
    {
        return $user->employee ?? throw ValidationException::withMessages([
            'attendance' => 'Your account is not linked to an employee record. Ask an administrator.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Attendance $a): array
    {
        $tz = $this->settings->timezone();
        $point = fn (?float $lat, ?float $lng, ?int $acc) => $lat === null ? null : ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => $acc];

        return [
            'id' => $a->id,
            'employee' => $a->relationLoaded('employee') ? [
                'id' => $a->employee->id,
                'full_name' => $a->employee->full_name,
                'employee_code' => $a->employee->employee_code,
            ] : null,
            'date' => $a->date->toDateString(),
            'check_in' => $a->check_in_at?->setTimezone($tz)->format('H:i'),
            'check_out' => $a->check_out_at?->setTimezone($tz)->format('H:i'),
            'check_in_location' => $point($a->check_in_latitude, $a->check_in_longitude, $a->check_in_accuracy),
            'check_out_location' => $point($a->check_out_latitude, $a->check_out_longitude, $a->check_out_accuracy),
            'late_minutes' => $a->late_minutes,
            'worked_minutes' => $a->worked_minutes,
            'note' => $a->note,
            'edited_by' => $a->relationLoaded('editor') ? $a->editor?->name : null,
        ];
    }
}
