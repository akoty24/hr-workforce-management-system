<?php

namespace App\Payroll;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates, recalculates and finalizes monthly payrolls. */
final class PayrollService
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function create(int $year, int $month, User $creator): PayrollRun
    {
        if (PayrollRun::query()->where('year', $year)->where('month', $month)->exists()) {
            throw ValidationException::withMessages(['month' => 'A payroll for this month already exists.']);
        }

        return DB::transaction(function () use ($year, $month, $creator) {
            $run = PayrollRun::create(['year' => $year, 'month' => $month, 'created_by' => $creator->id]);
            $this->calculate($run);

            return $run;
        });
    }

    /** (Re)builds every payslip of a draft, keeping manual items per employee. */
    public function calculate(PayrollRun $run): void
    {
        $this->ensureDraft($run);

        DB::transaction(function () use ($run) {
            $items = $run->payslips()->pluck('items', 'employee_id')->all();
            $run->payslips()->delete();

            $endOfMonth = CarbonImmutable::create($run->year, $run->month, 1)->endOfMonth()->toDateString();
            $employees = Employee::query()
                ->where('status', '!=', EmployeeStatus::Terminated->value)
                ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $endOfMonth))
                ->orderBy('first_name')
                ->get();

            foreach ($employees as $employee) {
                $fields = $this->calculator->calculate($employee, $run->year, $run->month, $this->decode($items[$employee->id] ?? null));
                if ($fields) {
                    $run->payslips()->create($fields + ['employee_id' => $employee->id]);
                }
            }
        });
    }

    /**
     * @param  list<array{type: string, label: string, amount: float|int|string}>  $items
     */
    public function updateItems(Payslip $payslip, array $items): Payslip
    {
        $run = $payslip->run;
        $this->ensureDraft($run);

        $fields = $this->calculator->calculate($payslip->employee, $run->year, $run->month, $items);
        abort_if($fields === null, 422, 'This employee no longer has a salary for this month.');
        $payslip->update($fields);

        return $payslip;
    }

    public function finalize(PayrollRun $run): void
    {
        $this->ensureDraft($run);
        $run->update(['status' => PayrollRun::FINALIZED, 'finalized_at' => now()]);
    }

    private function ensureDraft(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['payroll' => 'This payroll is finalized and can no longer change.']);
        }
    }

    /** @return list<array{type: string, label: string, amount: float|int|string}> */
    private function decode(mixed $items): array
    {
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        return is_array($items) ? array_values($items) : [];
    }
}
