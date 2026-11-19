<?php

namespace App\Payroll;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveStatus;
use App\Models\Salary;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Calculates one employee's pay for one month.
 *
 *   day rate     = monthly salary / payroll_day_divisor (30 by default)
 *   minute rate  = day rate / scheduled minutes per day
 *   gross        = salary + allowances/bonuses + overtime
 *                  − absence − unpaid leave − lateness
 *   social ins.  = rate % × salary clamped to [insurable_min, insurable_max]
 *   income tax   = Egyptian progressive brackets on (gross − social ins.) × 12,
 *                  after the 20,000 annual personal exemption, ÷ 12
 *   net          = gross − social ins. − income tax − other deductions
 *
 * Absence counts only working days up to today (company time zone) on or
 * after the hire date, with no check-in and no approved leave — and only in
 * months where the company recorded any attendance at all.
 */
final class PayrollCalculator
{
    /** Annual personal exemption (EGP). */
    public const TAX_EXEMPTION = 20000;

    /**
     * Annual brackets: [upper bound, rate %]. Law 7/2024 schedule, simplified
     * (no loss of lower brackets for very high incomes). Companies must verify
     * with their accountant; it can be switched off in Settings.
     */
    public const TAX_BRACKETS = [
        [40000, 0.0],
        [55000, 10.0],
        [70000, 15.0],
        [200000, 20.0],
        [400000, 22.5],
        [1200000, 25.0],
        [PHP_INT_MAX, 27.5],
    ];

    public function __construct(private readonly CompanySettings $settings) {}

    /**
     * @param  list<array{type: string, label: string, amount: float|int|string}>  $items
     * @return array<string, mixed>|null payslip fields, or null without a salary
     */
    public function calculate(Employee $employee, int $year, int $month, array $items = [], ?CarbonImmutable $today = null): ?array
    {
        $settings = $this->settings->all();
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        $salary = Salary::currentFor([$employee->id], $end->toDateString())[$employee->id] ?? null;
        if (! $salary) {
            return null;
        }

        $base = (float) $salary->amount;
        $dailyMinutes = $this->settings->dailyMinutes();
        $dayRate = $base / max(1, $settings['payroll_day_divisor']);
        $minuteRate = $dayRate / $dailyMinutes;

        // Working days of the month (weekend and public holidays excluded).
        $weekend = config('hr.weekend_days', []);
        $holidays = Holiday::datesBetween($start, $end);
        $workingDays = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! in_array($day->dayOfWeekIso, $weekend, true) && ! isset($holidays[$day->toDateString()])) {
                $workingDays[$day->toDateString()] = true;
            }
        }

        // Approved leave in the month, day by day.
        $leaveDays = [];
        $unpaidDays = 0;
        $leaves = LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get();
        foreach ($leaves as $leave) {
            foreach (CarbonPeriod::create(max($leave->start_date->toDateString(), $start->toDateString()), min($leave->end_date->toDateString(), $end->toDateString())) as $day) {
                $date = $day->toDateString();
                $leaveDays[$date] = true;
                if (isset($workingDays[$date]) && $leave->leaveType && ! $leave->leaveType->is_paid) {
                    $unpaidDays++;
                }
            }
        }

        // Attendance in the month.
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();
        $present = [];
        $lateMinutes = 0;
        $overtimeMinutes = 0;
        foreach ($attendance as $record) {
            if ($record->check_in_at) {
                $present[$record->date->toDateString()] = true;
            }
            $lateMinutes += (int) $record->late_minutes;
            if ($record->worked_minutes !== null) {
                $overtimeMinutes += max(0, (int) $record->worked_minutes - $dailyMinutes);
            }
        }

        // Absent: past working days since hiring with neither attendance nor leave.
        // Only when the company actually records attendance that month; a company
        // that does not use check-in must not see everyone marked absent.
        $absentDays = 0;
        $companyTracksAttendance = Attendance::query()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->exists();
        if ($settings['deduct_absence'] && $companyTracksAttendance) {
            $today ??= CarbonImmutable::now($settings['timezone'])->startOfDay();
            $from = $employee->hire_date && $employee->hire_date->toDateString() > $start->toDateString()
                ? $employee->hire_date->toDateString() : $start->toDateString();
            foreach (array_keys($workingDays) as $date) {
                if ($date >= $from && $date <= $today->toDateString() && ! isset($present[$date]) && ! isset($leaveDays[$date])) {
                    $absentDays++;
                }
            }
        }

        $additions = 0.0;
        $otherDeductions = 0.0;
        $cleanItems = [];
        foreach ($items as $item) {
            $amount = round(abs((float) $item['amount']), 2);
            if ($amount <= 0) {
                continue;
            }
            $type = $item['type'] === 'deduction' ? 'deduction' : ($item['type'] === 'bonus' ? 'bonus' : 'allowance');
            $type === 'deduction' ? $otherDeductions += $amount : $additions += $amount;
            $cleanItems[] = ['type' => $type, 'label' => trim((string) $item['label']), 'amount' => $amount];
        }

        $absence = $absentDays * $dayRate;
        $unpaid = $unpaidDays * $dayRate;
        $late = $lateMinutes * $minuteRate * $settings['late_multiplier'];
        $overtime = $overtimeMinutes * $minuteRate * $settings['overtime_multiplier'];
        $gross = max(0, $base + $additions + $overtime - $absence - $unpaid - $late);

        $insurance = $settings['social_insurance_enabled']
            ? min(max($base, $settings['insurable_min']), $settings['insurable_max']) * $settings['social_insurance_rate'] / 100
            : 0.0;
        $tax = $settings['income_tax_enabled'] ? self::monthlyTax(max(0, $gross - $insurance)) : 0.0;
        $net = $gross - $insurance - $tax - $otherDeductions;

        return [
            'currency' => $salary->currency,
            'base_salary' => round($base, 2),
            'working_days' => count($workingDays),
            'absent_days' => $absentDays,
            'unpaid_leave_days' => $unpaidDays,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'absence_deduction' => round($absence, 2),
            'unpaid_leave_deduction' => round($unpaid, 2),
            'late_deduction' => round($late, 2),
            'overtime_pay' => round($overtime, 2),
            'additions' => round($additions, 2),
            'other_deductions' => round($otherDeductions, 2),
            'gross' => round($gross, 2),
            'social_insurance' => round($insurance, 2),
            'income_tax' => round($tax, 2),
            'net' => round($net, 2),
            'items' => $cleanItems,
        ];
    }

    /** Monthly income tax for a monthly taxable income. */
    public static function monthlyTax(float $monthlyTaxable): float
    {
        $annual = max(0, $monthlyTaxable * 12 - self::TAX_EXEMPTION);
        $tax = 0.0;
        $lower = 0;

        foreach (self::TAX_BRACKETS as [$upper, $rate]) {
            if ($annual <= $lower) {
                break;
            }
            $tax += (min($annual, $upper) - $lower) * $rate / 100;
            $lower = $upper;
        }

        return $tax / 12;
    }
}
