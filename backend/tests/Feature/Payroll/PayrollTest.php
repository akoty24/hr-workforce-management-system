<?php

namespace Tests\Feature\Payroll;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Salary;
use App\Models\Tenant;
use App\Models\User;
use App\Payroll\PayrollCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $hr;

    private User $staff;

    private Employee $mona;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test', 'hr.weekend_days' => [5, 6]]);
        $this->acme = $this->createTenant('acme');

        // February 2026: 28 days, 8 Fridays/Saturdays -> 20 working days.
        [$this->hr, $this->staff, $this->mona] = $this->asTenant($this->acme, function () {
            $hr = User::factory()->create(['role' => 'hr']);
            $staff = User::factory()->create();
            $mona = Employee::create([
                'employee_code' => 'E-1', 'first_name' => 'Mona', 'last_name' => 'Hassan',
                'status' => 'active', 'hire_date' => '2025-01-01', 'user_id' => $staff->id,
            ]);
            Salary::create(['employee_id' => $mona->id, 'amount' => 9000, 'currency' => 'EGP', 'effective_from' => '2025-01-01']);

            $unpaid = LeaveType::create(['name' => 'Unpaid', 'is_paid' => false]);
            $annual = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21]);
            // Feb 2: unpaid leave. Feb 3: paid leave. Feb 4: absent (no record).
            foreach ([[$unpaid, '2026-02-02'], [$annual, '2026-02-03']] as [$type, $day]) {
                LeaveRequest::create([
                    'employee_id' => $mona->id, 'leave_type_id' => $type->id, 'start_date' => $day,
                    'end_date' => $day, 'days' => 1, 'status' => 'approved',
                ]);
            }

            foreach (CarbonPeriod::create('2026-02-01', '2026-02-28') as $day) {
                $date = $day->toDateString();
                if (in_array($day->dayOfWeekIso, [5, 6], true) || in_array($date, ['2026-02-02', '2026-02-03', '2026-02-04'], true)) {
                    continue;
                }
                Attendance::create([
                    'employee_id' => $mona->id, 'date' => $date,
                    'check_in_at' => "{$date} 07:00:00", 'check_out_at' => "{$date} 15:00:00",
                    // Feb 10: 30 minutes late. Feb 11: one hour overtime (9h worked).
                    'late_minutes' => $date === '2026-02-10' ? 30 : 0,
                    'worked_minutes' => $date === '2026-02-11' ? 540 : 480,
                ]);
            }

            return [$hr, $staff, $mona];
        });
    }

    private function api(string $path): string
    {
        return "http://acme.example.test/api/v1{$path}";
    }

    public function test_full_month_calculation(): void
    {
        $this->actingAs($this->hr, 'web');

        $run = $this->postJson($this->api('/payroll-runs'), ['year' => 2026, 'month' => 2])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(1, 'data.payslips')
            ->json('data');

        $slip = $run['payslips'][0];
        $this->assertSame(20, $slip['working_days']);
        $this->assertSame(1, $slip['absent_days']);
        $this->assertSame(1, $slip['unpaid_leave_days']);
        $this->assertSame(30, $slip['late_minutes']);
        $this->assertSame(60, $slip['overtime_minutes']);
        // Day rate 9000/30 = 300; minute rate 300/480 = 0.625.
        $this->assertEquals(300, $slip['absence_deduction']);
        $this->assertEquals(300, $slip['unpaid_leave_deduction']);
        $this->assertEquals(18.75, $slip['late_deduction']);
        $this->assertEquals(50.63, $slip['overtime_pay']);

        // Add a transport allowance and a loan deduction.
        $slip = $this->putJson($this->api("/payslips/{$slip['id']}"), ['items' => [
            ['type' => 'allowance', 'label' => 'Transport', 'amount' => 500],
            ['type' => 'deduction', 'label' => 'Loan', 'amount' => 100],
        ]])->assertOk()->json('data');

        // gross = 9000 + 500 + 50.625 − 300 − 300 − 18.75 = 8931.875
        $this->assertEquals(8931.88, $slip['gross']);
        $this->assertEquals(990, $slip['social_insurance']);       // 11% of 9000
        $this->assertEquals(400.88, $slip['income_tax']);          // (8941.875×12 − 20000) brackets ÷ 12
        $this->assertEquals(7441.00, $slip['net']);                // gross − 990 − 400.875 − 100
        $this->assertCount(2, $slip['items']);

        // Recalculating keeps the manual items.
        $this->postJson($this->api("/payroll-runs/{$run['id']}/recalculate"))->assertOk()
            ->assertJsonPath('data.payslips.0.net', 7441);
    }

    public function test_finalize_locks_and_employees_see_only_finalized_own_payslips(): void
    {
        $this->actingAs($this->hr, 'web');
        $id = $this->postJson($this->api('/payroll-runs'), ['year' => 2026, 'month' => 2])->json('data.id');

        // One payroll per month.
        $this->postJson($this->api('/payroll-runs'), ['year' => 2026, 'month' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('month');

        $this->actingAs($this->staff, 'web');
        $this->getJson($this->api('/payroll-runs'))->assertForbidden();
        $this->getJson($this->api('/my/payslips'))->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->hr, 'web');
        $this->postJson($this->api("/payroll-runs/{$id}/finalize"))->assertOk()->assertJsonPath('data.status', 'finalized');
        $this->postJson($this->api("/payroll-runs/{$id}/recalculate"))->assertUnprocessable();
        $this->deleteJson($this->api("/payroll-runs/{$id}"))->assertStatus(409);
        $this->get($this->api("/payroll-runs/{$id}/export"))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($this->staff, 'web');
        $this->getJson($this->api('/my/payslips'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period.month', 2);
    }

    public function test_a_draft_payroll_can_be_deleted_with_its_payslips(): void
    {
        $this->actingAs($this->hr, 'web');
        $id = $this->postJson($this->api('/payroll-runs'), ['year' => 2026, 'month' => 2])->json('data.id');

        $this->deleteJson($this->api("/payroll-runs/{$id}"))->assertNoContent();
        $this->getJson($this->api('/payroll-runs'))->assertJsonCount(0, 'data');
        // Employees with payroll or salary history are kept.
        $this->deleteJson($this->api("/employees/{$this->mona->id}"))->assertStatus(409);
    }

    public function test_income_tax_brackets(): void
    {
        // Annual taxable 0 after the 20,000 exemption -> no tax.
        $this->assertEqualsWithDelta(0, PayrollCalculator::monthlyTax(1500), 0.001);
        // 6,000/month -> 72,000 − 20,000 = 52,000: 12,000 × 10% = 1,200/yr.
        $this->assertEqualsWithDelta(100, PayrollCalculator::monthlyTax(6000), 0.001);
        // 20,000/month -> 220,000: 1,500 + 2,250 + 26,000 + 4,500 = 34,250/yr.
        $this->assertEqualsWithDelta(34250 / 12, PayrollCalculator::monthlyTax(20000), 0.001);
    }

    public function test_no_absence_when_the_company_does_not_record_attendance(): void
    {
        // March 2026 has no attendance records at all for the company.
        $result = $this->asTenant($this->acme, fn () => app(PayrollCalculator::class)->calculate($this->mona->fresh(), 2026, 3));

        $this->assertSame(0, $result['absent_days']);
        $this->assertEquals(0, $result['absence_deduction']);
    }

    public function test_absence_is_not_counted_before_hiring_or_in_the_future(): void
    {
        $calculator = app(PayrollCalculator::class);

        $result = $this->asTenant($this->acme, function () use ($calculator) {
            $this->mona->update(['hire_date' => '2026-02-15']);

            // As of Feb 18: working days Feb 15-18 (Sun-Wed) are all present -> no absence.
            return $calculator->calculate($this->mona->fresh(), 2026, 2, [], CarbonImmutable::parse('2026-02-18'));
        });

        $this->assertSame(0, $result['absent_days']);
    }
}
