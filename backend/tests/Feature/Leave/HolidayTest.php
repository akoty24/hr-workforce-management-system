<?php

namespace Tests\Feature\Leave;

use App\Leave\LeaveService;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $hr;

    private User $staffUser;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00')); // Wednesday
        config(['hr.weekend_days' => [5, 6]]);
        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->hr = User::factory()->create(['role' => UserRole::Hr]);
            $this->staffUser = User::factory()->create();
            Employee::create(['employee_code' => 'E1', 'first_name' => 'Mona', 'last_name' => 'H', 'user_id' => $this->staffUser->id]);
            $this->annual = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21]);
        });
    }

    public function test_hr_manages_holidays_and_everyone_sees_them(): void
    {
        $this->actingAs($this->hr, 'web');

        $id = $this->postJson('/api/v1/holidays', ['name' => 'Eid', 'start_date' => '2026-12-07', 'end_date' => '2026-12-09'])
            ->assertCreated()
            ->assertJsonPath('data.days', 3)
            ->json('data.id');

        // One-day holiday: end date defaults to the start date.
        $this->postJson('/api/v1/holidays', ['name' => 'National Day', 'start_date' => '2026-12-20'])
            ->assertCreated()
            ->assertJsonPath('data.end_date', '2026-12-20');

        $this->postJson('/api/v1/holidays', ['name' => 'Bad', 'start_date' => '2026-12-10', 'end_date' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $this->putJson("/api/v1/holidays/{$id}", ['name' => 'Eid al-Adha', 'start_date' => '2026-12-07', 'end_date' => '2026-12-08'])
            ->assertOk()->assertJsonPath('data.days', 2);

        $this->actingAs($this->staffUser, 'web');
        $this->getJson('/api/v1/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/holidays', ['name' => 'Mine', 'start_date' => '2026-12-21'])->assertForbidden();
        $this->deleteJson("/api/v1/holidays/{$id}")->assertForbidden();
    }

    public function test_holidays_are_not_counted_as_leave_days(): void
    {
        $this->asTenant($this->acme, fn () => Holiday::create(['name' => 'Eid', 'start_date' => '2026-12-07', 'end_date' => '2026-12-08']));

        // Sun 6 .. Thu 10 Dec = 5 working days, minus Mon 7 + Tue 8 holiday = 3.
        $this->assertSame(3, $this->asTenant($this->acme, fn () => app(LeaveService::class)->workingDays(
            CarbonImmutable::parse('2026-12-06'), CarbonImmutable::parse('2026-12-10'),
        )));

        $this->actingAs($this->staffUser, 'web');
        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $this->annual->id, 'start_date' => '2026-12-06', 'end_date' => '2026-12-10'])
            ->assertCreated()->assertJsonPath('data.days', 3);

        // Only holiday days: nothing to request.
        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $this->annual->id, 'start_date' => '2026-12-07', 'end_date' => '2026-12-07'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_no_hourly_permission_on_a_holiday(): void
    {
        $this->asTenant($this->acme, fn () => Holiday::create(['name' => 'National Day', 'start_date' => '2026-12-03', 'end_date' => '2026-12-03']));
        $this->actingAs($this->staffUser, 'web');

        $this->postJson('/api/v1/short-leaves', ['date' => '2026-12-03', 'start_time' => '09:00', 'end_time' => '10:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_holidays_are_isolated_per_tenant(): void
    {
        $this->asTenant($this->acme, fn () => Holiday::create(['name' => 'Acme Day', 'start_date' => '2026-12-03', 'end_date' => '2026-12-03']));

        $globex = $this->createTenant('globex');
        $this->asTenant($globex, function () {
            $this->assertSame(0, Holiday::query()->count());
            $this->assertSame([], Holiday::datesBetween(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31')));
        });
    }
}
