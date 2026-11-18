<?php

namespace Tests\Feature\Leave;

use App\Leave\LeaveService;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class ProratedAllowanceTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00'));
        config(['hr.weekend_days' => [5, 6]]);
        $this->acme = $this->createTenant('acme');
    }

    /**
     * @return array<string, array{?string, int, ?int}>
     */
    public static function hireDates(): array
    {
        return [
            'hired a previous year' => ['2020-05-10', 2026, 21],
            'no hire date' => [null, 2026, 21],
            'hired 1 January' => ['2026-01-01', 2026, 21],
            'hired 15 July (July counts)' => ['2026-07-15', 2026, 11],   // 21 * 6 / 12 = 10.5
            'hired 16 July (July skipped)' => ['2026-07-16', 2026, 9],   // 21 * 5 / 12 = 8.75
            'hired 20 December' => ['2026-12-20', 2026, 0],
            'year before hire' => ['2026-03-01', 2025, 0],
            'year after hire is full' => ['2026-07-16', 2027, 21],
        ];
    }

    #[DataProvider('hireDates')]
    public function test_allowance_is_prorated_in_the_hire_year(?string $hired, int $year, int $expected): void
    {
        $this->asTenant($this->acme, function () use ($hired, $year, $expected) {
            $type = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21, 'prorate_first_year' => true]);
            $employee = Employee::create(['employee_code' => 'E1', 'first_name' => 'A', 'last_name' => 'B', 'hire_date' => $hired]);

            $this->assertSame($expected, app(LeaveService::class)->allowance($employee, $type, $year));
        });
    }

    public function test_non_prorated_and_unlimited_types_ignore_the_hire_date(): void
    {
        $this->asTenant($this->acme, function () {
            $employee = Employee::create(['employee_code' => 'E1', 'first_name' => 'A', 'last_name' => 'B', 'hire_date' => '2026-10-01']);
            $sick = LeaveType::create(['name' => 'Sick', 'days_per_year' => 15]);
            $unpaid = LeaveType::create(['name' => 'Unpaid', 'days_per_year' => null, 'prorate_first_year' => true]);

            $this->assertSame(15, app(LeaveService::class)->allowance($employee, $sick, 2026));
            $this->assertNull(app(LeaveService::class)->allowance($employee, $unpaid, 2026));
        });
    }

    public function test_balance_and_submission_use_the_prorated_allowance(): void
    {
        [$user, $type] = $this->asTenant($this->acme, function () {
            $user = User::factory()->create();
            Employee::create(['employee_code' => 'E1', 'first_name' => 'A', 'last_name' => 'B', 'hire_date' => '2026-10-01', 'user_id' => $user->id]);
            // Oct, Nov, Dec = 3 months => 21 * 3 / 12 = 5.25 => 5 days.
            $type = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21, 'prorate_first_year' => true]);

            return [$user, $type];
        });

        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.allowance', 5)
            ->assertJsonPath('data.0.full_allowance', 21)
            ->assertJsonPath('data.0.remaining', 5);

        // Sun 22 .. Sun 29 Nov 2026 = 6 working days > 5.
        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $type->id, 'start_date' => '2026-11-22', 'end_date' => '2026-11-29'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        // Sun 22 .. Thu 26 Nov = 5 working days: fits exactly.
        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $type->id, 'start_date' => '2026-11-22', 'end_date' => '2026-11-26'])
            ->assertCreated();
    }

    public function test_leave_cannot_start_before_the_hire_date(): void
    {
        [$user, $type] = $this->asTenant($this->acme, function () {
            $user = User::factory()->create();
            Employee::create(['employee_code' => 'E1', 'first_name' => 'A', 'last_name' => 'B', 'hire_date' => '2026-12-01', 'user_id' => $user->id]);

            return [$user, LeaveType::create(['name' => 'Unpaid', 'days_per_year' => null])];
        });

        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $type->id, 'start_date' => '2026-11-22', 'end_date' => '2026-11-24'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_admin_can_toggle_proration_on_a_leave_type(): void
    {
        $admin = $this->asTenant($this->acme, fn () => User::factory()->admin()->create());
        $this->actingAs($admin, 'web');

        $this->postJson('/api/v1/leave-types', ['name' => 'Annual', 'days_per_year' => 21, 'prorate_first_year' => true])
            ->assertCreated()
            ->assertJsonPath('data.prorate_first_year', true);
    }
}
