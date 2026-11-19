<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Payroll\PayrollService;
use App\Reports\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Monthly payroll. Admins and HR create a draft, review and adjust it, then
 * finalize it. Employees see their own payslips once finalized.
 */
class PayrollController
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeHr($request);

        $runs = PayrollRun::query()
            ->withCount('payslips')
            ->withSum('payslips as total_gross', 'gross')
            ->withSum('payslips as total_net', 'net')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return response()->json(['data' => $runs->map(fn (PayrollRun $run) => $this->run($run))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeHr($request);
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $run = $this->payroll->create((int) $data['year'], (int) $data['month'], $request->user());

        return response()->json(['data' => $this->detail($run)], 201);
    }

    public function show(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorizeHr($request);

        return response()->json(['data' => $this->detail($payrollRun)]);
    }

    public function recalculate(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorizeHr($request);
        $this->payroll->calculate($payrollRun);

        return response()->json(['data' => $this->detail($payrollRun)]);
    }

    public function finalize(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorizeHr($request);
        $this->payroll->finalize($payrollRun);

        return response()->json(['data' => $this->detail($payrollRun)]);
    }

    public function destroy(Request $request, PayrollRun $payrollRun): Response|JsonResponse
    {
        $this->authorizeHr($request);

        if (! $payrollRun->isDraft()) {
            return response()->json(['message' => 'A finalized payroll cannot be deleted.'], 409);
        }

        // Payslips first: the composite tenant foreign key does not cascade.
        DB::transaction(function () use ($payrollRun) {
            $payrollRun->payslips()->delete();
            $payrollRun->delete();
        });

        return response()->noContent();
    }

    public function updatePayslip(Request $request, Payslip $payslip): JsonResponse
    {
        $this->authorizeHr($request);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:30'],
            'items.*.type' => ['required', 'in:allowance,bonus,deduction'],
            'items.*.label' => ['required', 'string', 'max:100'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $slip = $this->payroll->updateItems($payslip->load('run', 'employee'), $data['items']);

        return response()->json(['data' => $this->payslip($slip->load('employee'))]);
    }

    public function export(Request $request, PayrollRun $payrollRun): BinaryFileResponse
    {
        $this->authorizeHr($request);

        $headers = ['Code', 'Employee', 'Currency', 'Salary', 'Working days', 'Absent days', 'Unpaid leave days',
            'Late (min)', 'Overtime (min)', 'Absence', 'Unpaid leave', 'Lateness', 'Overtime pay', 'Allowances & bonuses',
            'Gross', 'Social insurance', 'Income tax', 'Other deductions', 'Net'];
        $rows = $payrollRun->payslips()->with('employee')->get()->map(fn (Payslip $p) => [
            $p->employee->employee_code, $p->employee->full_name, $p->currency, (float) $p->base_salary, $p->working_days,
            $p->absent_days, $p->unpaid_leave_days, $p->late_minutes, $p->overtime_minutes, (float) $p->absence_deduction,
            (float) $p->unpaid_leave_deduction, (float) $p->late_deduction, (float) $p->overtime_pay, (float) $p->additions,
            (float) $p->gross, (float) $p->social_insurance, (float) $p->income_tax, (float) $p->other_deductions, (float) $p->net,
        ]);

        $period = sprintf('%04d-%02d', $payrollRun->year, $payrollRun->month);
        $path = XlsxWriter::write("Payroll {$period}", $headers, $rows);

        return response()
            ->download($path, "payroll-{$period}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /** The signed-in employee's finalized payslips. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employeeId = $user->employee?->id;

        $slips = $employeeId === null ? collect() : Payslip::query()
            ->with(['run', 'employee'])
            ->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->where('status', PayrollRun::FINALIZED))
            ->get()
            ->sortByDesc(fn (Payslip $p) => $p->run->year * 100 + $p->run->month)
            ->values();

        return response()->json(['data' => $slips->map(fn (Payslip $p) => $this->payslip($p))]);
    }

    private function authorizeHr(Request $request): void
    {
        abort_unless($request->user()->managesHr(), 403);
    }

    /** @return array<string, mixed> */
    private function run(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'year' => $run->year,
            'month' => $run->month,
            'status' => $run->status,
            'finalized_at' => $run->finalized_at?->toIso8601String(),
            'payslips_count' => (int) ($run->payslips_count ?? $run->payslips()->count()),
            'total_gross' => round((float) ($run->total_gross ?? $run->payslips()->sum('gross')), 2),
            'total_net' => round((float) ($run->total_net ?? $run->payslips()->sum('net')), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(PayrollRun $run): array
    {
        $run->unsetRelation('payslips');
        $slips = $run->payslips()->with('employee')->get()->sortBy(fn (Payslip $p) => $p->employee->full_name)->values();

        return $this->run($run) + ['payslips' => $slips->map(fn (Payslip $p) => $this->payslip($p))];
    }

    /** @return array<string, mixed> */
    private function payslip(Payslip $p): array
    {
        $money = [];
        foreach (Payslip::MONEY as $field) {
            $money[$field] = (float) $p->{$field};
        }

        return [
            'id' => $p->id,
            'employee' => ['id' => $p->employee->id, 'full_name' => $p->employee->full_name, 'employee_code' => $p->employee->employee_code],
            'period' => $p->relationLoaded('run') ? ['year' => $p->run->year, 'month' => $p->run->month] : null,
            'currency' => $p->currency,
            'working_days' => $p->working_days,
            'absent_days' => $p->absent_days,
            'unpaid_leave_days' => $p->unpaid_leave_days,
            'late_minutes' => $p->late_minutes,
            'overtime_minutes' => $p->overtime_minutes,
            'items' => $p->items ?? [],
        ] + $money;
    }
}
