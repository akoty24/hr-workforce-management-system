<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's pay for one payroll run, with the calculation breakdown.
 *
 * @property string $id
 * @property string $payroll_run_id
 * @property string $employee_id
 * @property string $currency
 * @property string $base_salary
 * @property int $working_days
 * @property int $absent_days
 * @property int $unpaid_leave_days
 * @property int $late_minutes
 * @property int $overtime_minutes
 * @property string $gross
 * @property string $net
 * @property list<array{type: string, label: string, amount: float}>|null $items
 * @property-read PayrollRun $run
 * @property-read Employee $employee
 */
class Payslip extends Model
{
    use BelongsToTenant, HasUuids;

    public const MONEY = [
        'base_salary', 'absence_deduction', 'unpaid_leave_deduction', 'late_deduction', 'overtime_pay',
        'additions', 'other_deductions', 'gross', 'social_insurance', 'income_tax', 'net',
    ];

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'currency', 'base_salary', 'working_days', 'absent_days',
        'unpaid_leave_days', 'late_minutes', 'overtime_minutes', 'absence_deduction', 'unpaid_leave_deduction',
        'late_deduction', 'overtime_pay', 'additions', 'other_deductions', 'gross', 'social_insurance',
        'income_tax', 'net', 'items',
    ];

    protected function casts(): array
    {
        return array_fill_keys(self::MONEY, 'decimal:2') + [
            'working_days' => 'integer',
            'absent_days' => 'integer',
            'unpaid_leave_days' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'items' => 'array',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
