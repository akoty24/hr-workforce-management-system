<?php

namespace App\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave business rules: working-day counting, balances, and the checks a
 * request must pass (dates, overlap, remaining balance).
 */
final class LeaveService
{
    public function workingDays(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $weekend = config('hr.weekend_days', []);
        $days = 0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! in_array($day->dayOfWeekIso, $weekend, true)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Days of a leave type the employee is entitled to in a calendar year
     * (null = unlimited).
     *
     * For prorated types, the hire year gets a share of the yearly allowance
     * for the months left in that year: the hire month counts when the
     * employee joined on or before the 15th. The result is rounded to the
     * nearest whole day. Years before the hire year get nothing.
     */
    public function allowance(Employee $employee, LeaveType $type, int $year): ?int
    {
        if ($type->days_per_year === null) {
            return null;
        }

        $hired = $employee->hire_date;

        if (! $type->prorate_first_year || $hired === null || $hired->year < $year) {
            return $type->days_per_year;
        }

        if ($hired->year > $year) {
            return 0;
        }

        $months = 12 - $hired->month + ($hired->day <= 15 ? 1 : 0);

        return (int) round($type->days_per_year * $months / 12);
    }

    /**
     * Balance per active leave type for a calendar year.
     *
     * @return list<array{leave_type: array{id: string, name: string}, allowance: int|null, full_allowance: int|null, approved: int, pending: int, remaining: int|null}>
     */
    public function balances(Employee $employee, int $year): array
    {
        $used = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', LeaveStatus::blocking())
            ->whereYear('start_date', $year)
            ->selectRaw('leave_type_id, status, SUM(days) as total')
            ->groupBy('leave_type_id', 'status')
            ->get();

        return LeaveType::query()->where('is_active', true)->orderBy('name')->get()
            ->map(function (LeaveType $type) use ($used, $employee, $year) {
                $approved = (int) $used->where('leave_type_id', $type->id)->where('status', LeaveStatus::Approved)->sum('total');
                $pending = (int) $used->where('leave_type_id', $type->id)->where('status', LeaveStatus::Pending)->sum('total');

                $allowance = $this->allowance($employee, $type, $year);

                return [
                    'leave_type' => ['id' => $type->id, 'name' => $type->name],
                    'allowance' => $allowance,
                    'full_allowance' => $type->days_per_year,
                    'approved' => $approved,
                    'pending' => $pending,
                    // Only approved leave reduces the balance; pending is shown separately.
                    'remaining' => $allowance === null ? null : max(0, $allowance - $approved),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Validate and create a pending request. The employee row is locked so
     * concurrent submissions cannot both pass the overlap/balance checks.
     *
     * @throws ValidationException
     */
    public function submit(Employee $employee, LeaveType $type, CarbonImmutable $start, CarbonImmutable $end, ?string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $type, $start, $end, $reason) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if (! $type->is_active) {
                throw ValidationException::withMessages(['leave_type_id' => 'This leave type is no longer available.']);
            }

            if ($start->year !== $end->year) {
                throw ValidationException::withMessages(['end_date' => 'A request cannot span two calendar years. Split it into two requests.']);
            }

            if ($employee->hire_date !== null && $start->lt($employee->hire_date)) {
                throw ValidationException::withMessages(['start_date' => 'Leave cannot start before the hire date ('.$employee->hire_date->toDateString().').']);
            }

            $days = $this->workingDays($start, $end);

            if ($days === 0) {
                throw ValidationException::withMessages(['end_date' => 'The selected period contains no working days.']);
            }

            $overlaps = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', LeaveStatus::blocking())
                ->whereDate('start_date', '<=', $end)
                ->whereDate('end_date', '>=', $start)
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages(['start_date' => 'This period overlaps another pending or approved leave request.']);
            }

            $this->ensureBalance($employee, $type, $start->year, $days, 'end_date');

            return LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'reason' => $reason,
                'status' => LeaveStatus::Pending,
            ]);
        });
    }

    /**
     * Record a decision. Approval re-checks the balance (only approved leave
     * consumes it, so several pending requests may together exceed it); the
     * employee row is locked so two approvals cannot overspend.
     *
     * @throws ValidationException
     */
    public function decide(LeaveRequest $request, User $decider, LeaveStatus $status, ?string $note): LeaveRequest
    {
        if ($status !== LeaveStatus::Approved) {
            return $this->record($request, $decider, $status, $note);
        }

        return DB::transaction(function () use ($request, $decider, $status, $note) {
            Employee::query()->whereKey($request->employee_id)->lockForUpdate()->first();

            $type = $request->leaveType()->firstOrFail();
            $year = $request->start_date->year;

            $this->ensureBalance($request->employee()->firstOrFail(), $type, $year, $request->days, 'status');

            return $this->record($request, $decider, $status, $note);
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureBalance(Employee $employee, LeaveType $type, int $year, int $days, string $field): void
    {
        $allowance = $this->allowance($employee, $type, $year);

        if ($allowance === null) {
            return;
        }

        $approved = (int) LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('status', LeaveStatus::Approved)
            ->whereYear('start_date', $year)
            ->sum('days');

        $remaining = max(0, $allowance - $approved);

        if ($days > $remaining) {
            throw ValidationException::withMessages([
                $field => "Not enough balance: {$days} working day(s) requested, {$remaining} remaining for {$type->name} in {$year}.",
            ]);
        }
    }

    private function record(LeaveRequest $request, User $decider, LeaveStatus $status, ?string $note): LeaveRequest
    {
        $request->update([
            'status' => $status,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $request;
    }
}
