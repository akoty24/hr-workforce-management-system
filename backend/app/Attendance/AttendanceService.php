<?php

namespace App\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Check-in / check-out. The working day, lateness and worked time are
 * computed in the company time zone; timestamps are stored in UTC.
 */
final class AttendanceService
{
    public function __construct(private readonly CompanySettings $settings) {}

    /** Current moment in the company time zone. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->settings->timezone());
    }

    public function today(Employee $employee): ?Attendance
    {
        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $this->now()->toDateString())
            ->first();
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|int|null}  $location
     *
     * @throws ValidationException
     */
    public function checkIn(Employee $employee, array $location): Attendance
    {
        $this->ensureLocation($location);

        return DB::transaction(function () use ($employee, $location) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $now = $this->now();

            if ($this->today($employee)?->check_in_at) {
                throw ValidationException::withMessages(['attendance' => 'You have already checked in today.']);
            }

            return Attendance::create([
                'employee_id' => $employee->id,
                'date' => $now->toDateString(),
                'check_in_at' => $now->utc(),
                'check_in_latitude' => $location['latitude'] ?? null,
                'check_in_longitude' => $location['longitude'] ?? null,
                'check_in_accuracy' => isset($location['accuracy']) ? (int) round($location['accuracy']) : null,
                'late_minutes' => $this->lateMinutes($now),
            ]);
        });
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|int|null}  $location
     *
     * @throws ValidationException
     */
    public function checkOut(Employee $employee, array $location): Attendance
    {
        $this->ensureLocation($location);

        return DB::transaction(function () use ($employee, $location) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $record = $this->today($employee);

            if (! $record?->check_in_at) {
                throw ValidationException::withMessages(['attendance' => 'You have not checked in today.']);
            }

            if ($record->check_out_at) {
                throw ValidationException::withMessages(['attendance' => 'You have already checked out today.']);
            }

            $now = $this->now()->utc();

            $record->update([
                'check_out_at' => $now,
                'check_out_latitude' => $location['latitude'] ?? null,
                'check_out_longitude' => $location['longitude'] ?? null,
                'check_out_accuracy' => isset($location['accuracy']) ? (int) round($location['accuracy']) : null,
                'worked_minutes' => (int) $record->check_in_at->diffInMinutes($now),
            ]);

            return $record;
        });
    }

    /**
     * Create or correct a day's record by HR (times are company-local HH:MM).
     *
     * @throws ValidationException
     */
    public function record(Employee $employee, string $date, string $checkIn, ?string $checkOut, ?string $note, User $editor, ?Attendance $existing = null): Attendance
    {
        $tz = $this->settings->timezone();
        $in = CarbonImmutable::parse("{$date} {$checkIn}", $tz);
        $out = $checkOut ? CarbonImmutable::parse("{$date} {$checkOut}", $tz) : null;

        if ($out && $out->lte($in)) {
            throw ValidationException::withMessages(['check_out' => 'Check-out must be after check-in.']);
        }

        $duplicate = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['date' => 'This employee already has an attendance record for this day.']);
        }

        $values = [
            'employee_id' => $employee->id,
            'date' => $date,
            'check_in_at' => $in->utc(),
            'check_out_at' => $out?->utc(),
            'late_minutes' => $this->lateMinutes($in),
            'worked_minutes' => $out ? (int) $in->diffInMinutes($out) : null,
            'note' => $note,
            'edited_by' => $editor->id,
        ];

        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return Attendance::create($values);
    }

    /**
     * Minutes late against the company start time. Arrivals within the
     * grace period, and work on weekends or public holidays, are not late.
     */
    public function lateMinutes(CarbonImmutable $arrival): int
    {
        $settings = $this->settings->all();
        $local = $arrival->setTimezone($settings['timezone']);

        if (in_array($local->dayOfWeekIso, config('hr.weekend_days', []), true)
            || Holiday::datesBetween($local->startOfDay(), $local->startOfDay()) !== []) {
            return 0;
        }

        $start = $local->setTimeFromTimeString($settings['work_start']);
        $late = $local->gt($start) ? (int) $start->diffInMinutes($local) : 0;

        return $late > $settings['grace_minutes'] ? $late : 0;
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null}  $location
     *
     * @throws ValidationException
     */
    private function ensureLocation(array $location): void
    {
        $missing = ($location['latitude'] ?? null) === null || ($location['longitude'] ?? null) === null;

        if ($missing && $this->settings->all()['require_location']) {
            throw ValidationException::withMessages([
                'location' => 'Your location is required. Allow location access in your browser and try again.',
            ]);
        }
    }
}
