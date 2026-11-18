<?php

namespace App\Reports;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveStatus;
use App\Models\Salary;
use App\Models\ShortLeave;
use App\Settings\CompanySettings;

/**
 * Tabular HR reports for a date range, optionally limited to a department.
 * Each report returns headers + rows, shown in the UI or exported to Excel.
 */
final class ReportBuilder
{
    public const TYPES = ['summary', 'leave', 'permissions', 'attendance', 'salaries'];

    public function __construct(private readonly CompanySettings $settings) {}

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>}
     */
    public function build(string $type, string $from, string $to, ?string $departmentId): array
    {
        return match ($type) {
            'summary' => $this->summary($from, $to, $departmentId),
            'leave' => $this->leave($from, $to, $departmentId),
            'permissions' => $this->permissions($from, $to, $departmentId),
            'attendance' => $this->attendance($from, $to, $departmentId),
            'salaries' => $this->salaries($to, $departmentId),
        };
    }

    /** One row per employee: attendance, lateness, leave and permissions in the period. */
    private function summary(string $from, string $to, ?string $departmentId): array
    {
        $employees = $this->employees($departmentId)->get();
        $ids = $employees->pluck('id')->all();

        $attendance = Attendance::query()->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->selectRaw('employee_id, COUNT(*) as days, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days, SUM(late_minutes) as late_minutes, SUM(COALESCE(worked_minutes, 0)) as worked')
            ->groupBy('employee_id')->get()->keyBy('employee_id');

        $leave = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', LeaveStatus::Approved)
            ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)
            ->selectRaw('employee_id, SUM(days) as days')->groupBy('employee_id')->get()->keyBy('employee_id');

        $permissions = ShortLeave::query()->whereIn('employee_id', $ids)->where('status', LeaveStatus::Approved)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->selectRaw('employee_id, COUNT(*) as count, SUM(minutes) as minutes')->groupBy('employee_id')->get()->keyBy('employee_id');

        $rows = $employees->map(fn (Employee $e) => [
            $e->employee_code,
            $e->full_name,
            $e->department?->name,
            (int) ($attendance[$e->id]->days ?? 0),
            (int) ($attendance[$e->id]->late_days ?? 0),
            (int) ($attendance[$e->id]->late_minutes ?? 0),
            round(((int) ($attendance[$e->id]->worked ?? 0)) / 60, 1),
            (int) ($leave[$e->id]->days ?? 0),
            (int) ($permissions[$e->id]->count ?? 0),
            round(((int) ($permissions[$e->id]->minutes ?? 0)) / 60, 1),
        ])->values()->all();

        return [
            'title' => 'Summary',
            'headers' => ['Code', 'Employee', 'Department', 'Days attended', 'Late days', 'Late minutes', 'Hours worked', 'Approved leave days', 'Approved permissions', 'Permission hours'],
            'rows' => $rows,
        ];
    }

    private function leave(string $from, string $to, ?string $departmentId): array
    {
        $rows = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'decider'])
            ->whereHas('employee', fn ($q) => $this->inDepartment($q, $departmentId))
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $r) => [
                $r->employee->employee_code, $r->employee->full_name, $r->employee->department?->name,
                $r->leaveType->name, $r->start_date->toDateString(), $r->end_date->toDateString(), $r->days,
                ucfirst($r->status->value), $r->decider?->name, $r->reason,
            ])->values()->all();

        return [
            'title' => 'Leave',
            'headers' => ['Code', 'Employee', 'Department', 'Leave type', 'From', 'To', 'Working days', 'Status', 'Decided by', 'Reason'],
            'rows' => $rows,
        ];
    }

    private function permissions(string $from, string $to, ?string $departmentId): array
    {
        $rows = ShortLeave::query()
            ->with(['employee.department', 'decider'])
            ->whereHas('employee', fn ($q) => $this->inDepartment($q, $departmentId))
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->orderBy('date')->orderBy('start_time')
            ->get()
            ->map(fn (ShortLeave $p) => [
                $p->employee->employee_code, $p->employee->full_name, $p->employee->department?->name,
                $p->date->toDateString(), substr($p->start_time, 0, 5), substr($p->end_time, 0, 5), $p->minutes,
                ucfirst($p->status->value), $p->decider?->name, $p->reason,
            ])->values()->all();

        return [
            'title' => 'Permissions',
            'headers' => ['Code', 'Employee', 'Department', 'Date', 'From', 'To', 'Minutes', 'Status', 'Decided by', 'Reason'],
            'rows' => $rows,
        ];
    }

    private function attendance(string $from, string $to, ?string $departmentId): array
    {
        $tz = $this->settings->timezone();
        $map = fn (?float $lat, ?float $lng) => $lat === null ? null : "https://www.google.com/maps?q={$lat},{$lng}";

        $rows = Attendance::query()
            ->with(['employee.department'])
            ->whereHas('employee', fn ($q) => $this->inDepartment($q, $departmentId))
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->orderBy('date')->orderBy('check_in_at')
            ->get()
            ->map(fn (Attendance $a) => [
                $a->employee->employee_code, $a->employee->full_name, $a->employee->department?->name,
                $a->date->toDateString(),
                $a->check_in_at?->setTimezone($tz)->format('H:i'),
                $a->check_out_at?->setTimezone($tz)->format('H:i'),
                $a->worked_minutes === null ? null : round($a->worked_minutes / 60, 2),
                $a->late_minutes,
                $map($a->check_in_latitude, $a->check_in_longitude),
                $map($a->check_out_latitude, $a->check_out_longitude),
                $a->note,
            ])->values()->all();

        return [
            'title' => 'Attendance',
            'headers' => ['Code', 'Employee', 'Department', 'Date', 'Check in', 'Check out', 'Hours worked', 'Late minutes', 'Check-in location', 'Check-out location', 'Note'],
            'rows' => $rows,
        ];
    }

    /** Salary in force on the "to" date. */
    private function salaries(string $to, ?string $departmentId): array
    {
        $employees = $this->employees($departmentId)->get();
        $current = Salary::currentFor($employees->pluck('id')->all(), $to);

        $rows = $employees->map(fn (Employee $e) => [
            $e->employee_code, $e->full_name, $e->department?->name, $e->job_title,
            isset($current[$e->id]) ? (float) $current[$e->id]->amount : null,
            $current[$e->id]->currency ?? null,
            isset($current[$e->id]) ? $current[$e->id]->effective_from->toDateString() : null,
        ])->values()->all();

        return [
            'title' => 'Salaries',
            'headers' => ['Code', 'Employee', 'Department', 'Job title', 'Salary', 'Currency', 'Effective from'],
            'rows' => $rows,
        ];
    }

    private function employees(?string $departmentId)
    {
        return Employee::query()->with('department')
            ->where('status', '!=', 'terminated')
            ->tap(fn ($q) => $this->inDepartment($q, $departmentId))
            ->orderBy('employee_code');
    }

    private function inDepartment($query, ?string $departmentId)
    {
        return $query->when($departmentId, fn ($q, $id) => $q->where('department_id', $id));
    }
}
