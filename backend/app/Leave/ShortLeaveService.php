<?php

namespace App\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveStatus;
use App\Models\ShortLeave;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hourly permissions: one working day, a from/to time, at most
 * hr.short_leave_max_minutes long. They do not touch leave balances.
 */
final class ShortLeaveService
{
    /**
     * @param  string  $from  HH:MM
     * @param  string  $to  HH:MM
     *
     * @throws ValidationException
     */
    public function submit(Employee $employee, CarbonImmutable $date, string $from, string $to, ?string $reason): ShortLeave
    {
        $start = $date->setTimeFromTimeString($from);
        $end = $date->setTimeFromTimeString($to);

        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_time' => 'The end time must be after the start time.']);
        }

        $minutes = (int) $start->diffInMinutes($end);
        $max = (int) config('hr.short_leave_max_minutes', 120);

        if ($minutes > $max) {
            throw ValidationException::withMessages(['end_time' => 'A permission cannot be longer than '.self::duration($max).'.']);
        }

        if (in_array($date->dayOfWeekIso, config('hr.weekend_days', []), true)) {
            throw ValidationException::withMessages(['date' => 'The selected date is a weekend day.']);
        }

        if ($employee->hire_date !== null && $date->lt($employee->hire_date)) {
            throw ValidationException::withMessages(['date' => 'The date is before the hire date ('.$employee->hire_date->toDateString().').']);
        }

        return DB::transaction(function () use ($employee, $date, $start, $end, $minutes, $reason) {
            // Serialise submissions per employee so the overlap checks hold.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $startTime = $start->format('H:i:s');
            $endTime = $end->format('H:i:s');

            $overlaps = ShortLeave::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->whereIn('status', LeaveStatus::blocking())
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages(['start_time' => 'This time overlaps another pending or approved permission.']);
            }

            $onLeave = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', LeaveStatus::blocking())
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->exists();

            if ($onLeave) {
                throw ValidationException::withMessages(['date' => 'There is already a pending or approved leave on this day.']);
            }

            return ShortLeave::create([
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'minutes' => $minutes,
                'reason' => $reason,
                'status' => LeaveStatus::Pending,
            ]);
        });
    }

    public function decide(ShortLeave $shortLeave, User $decider, LeaveStatus $status, ?string $note): ShortLeave
    {
        $shortLeave->update([
            'status' => $status,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $shortLeave;
    }

    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = array_filter([
            $hours ? $hours.' hour'.($hours === 1 ? '' : 's') : null,
            $rest ? "{$rest} minutes" : null,
        ]);

        return implode(' ', $parts);
    }
}
