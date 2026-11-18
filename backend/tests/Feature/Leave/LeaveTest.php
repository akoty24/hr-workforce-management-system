<?php

namespace Tests\Feature\Leave;

use App\Leave\LeaveService;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    private User $staffUser;

    private User $otherUser;

    private Employee $staff;

    private Employee $other;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed "today": Wednesday 2026-12-02. Weekend: Friday + Saturday.
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00'));
        config(['hr.weekend_days' => [5, 6]]);

        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->admin = User::factory()->admin()->create();
            $this->staffUser = User::factory()->create();
            $this->otherUser = User::factory()->create();
            $this->staff = Employee::create(['employee_code' => 'E1', 'first_name' => 'Mona', 'last_name' => 'H', 'user_id' => $this->staffUser->id]);
            $this->other = Employee::create(['employee_code' => 'E2', 'first_name' => 'Omar', 'last_name' => 'K', 'user_id' => $this->otherUser->id]);
            $this->annual = LeaveType::create(['name' => 'Annual', 'days_per_year' => 5]);
        });
    }

    private function request(array $overrides = []): array
    {
        return $overrides + [
            'leave_type_id' => $this->annual->id,
            'start_date' => '2026-12-06', // Sunday
            'end_date' => '2026-12-08',   // Tuesday
            'reason' => 'Trip',
        ];
    }

    public function test_working_days_exclude_the_configured_weekend(): void
    {
        $service = app(LeaveService::class);

        // Wed 2 .. Tue 8 Dec: Fri 4 + Sat 5 excluded => 5 working days.
        $this->assertSame(5, $service->workingDays(CarbonImmutable::parse('2026-12-02'), CarbonImmutable::parse('2026-12-08')));

        config(['hr.weekend_days' => [6, 7]]);
        $this->assertSame(5, $service->workingDays(CarbonImmutable::parse('2026-12-02'), CarbonImmutable::parse('2026-12-08')));
    }

    public function test_employee_submits_and_admin_approves(): void
    {
        $this->actingAs($this->staffUser, 'web');

        $id = $this->postJson('/api/v1/leave-requests', $this->request())
            ->assertCreated()
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.employee.id', $this->staff->id)
            ->json('data.id');

        $this->postJson("/api/v1/leave-requests/{$id}/approve")->assertForbidden();

        $this->actingAs($this->admin, 'web');
        $this->postJson("/api/v1/leave-requests/{$id}/approve", ['note' => 'OK'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision_note', 'OK');

        $this->postJson("/api/v1/leave-requests/{$id}/reject")->assertStatus(409);

        $this->actingAs($this->staffUser, 'web');
        $this->getJson('/api/v1/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.allowance', 5)
            ->assertJsonPath('data.0.approved', 3)
            ->assertJsonPath('data.0.remaining', 2);
    }

    public function test_rules_overlap_balance_past_dates_and_weekends(): void
    {
        $this->actingAs($this->staffUser, 'web');
        $this->postJson('/api/v1/leave-requests', $this->request())->assertCreated();

        // Overlaps the pending request.
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-08', 'end_date' => '2026-12-09']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        // Sun 13 .. Mon 21 = 7 working days > 5 allowance.
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-13', 'end_date' => '2026-12-21']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);

        // Past start (non-admin).
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-01', 'end_date' => '2026-12-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        // Only weekend days.
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-11', 'end_date' => '2026-12-12']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);

        // Spanning two years.
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-30', 'end_date' => '2027-01-03']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);

        // End before start.
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-20', 'end_date' => '2026-12-19']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    }

    public function test_only_approved_leave_reduces_the_balance(): void
    {
        $this->actingAs($this->staffUser, 'web');

        // Two pending requests of 3 days each: 6 > 5, but pending does not consume the balance.
        $first = $this->postJson('/api/v1/leave-requests', $this->request())->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-13', 'end_date' => '2026-12-15']))
            ->assertCreated()->json('data.id');

        $this->getJson('/api/v1/leave-balances')
            ->assertJsonPath('data.0.pending', 6)
            ->assertJsonPath('data.0.remaining', 5);

        $this->actingAs($this->admin, 'web');
        $this->postJson("/api/v1/leave-requests/{$first}/approve")->assertOk();

        // Only 2 days left: approving the second (3 days) is refused and it stays pending.
        $this->postJson("/api/v1/leave-requests/{$second}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/leave-requests/{$second}/reject")->assertOk();

        $this->actingAs($this->staffUser, 'web');
        $this->getJson('/api/v1/leave-balances')
            ->assertJsonPath('data.0.approved', 3)
            ->assertJsonPath('data.0.pending', 0)
            ->assertJsonPath('data.0.remaining', 2);
    }

    public function test_cancelling_frees_the_balance(): void
    {
        $this->actingAs($this->staffUser, 'web');
        $id = $this->postJson('/api/v1/leave-requests', $this->request())->json('data.id');

        $this->postJson("/api/v1/leave-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/leave-balances')->assertJsonPath('data.0.remaining', 5);

        // Same dates can now be requested again.
        $this->postJson('/api/v1/leave-requests', $this->request())->assertCreated();
    }

    public function test_employees_only_see_and_cancel_their_own_requests(): void
    {
        $this->actingAs($this->otherUser, 'web');
        $othersId = $this->postJson('/api/v1/leave-requests', $this->request())->json('data.id');

        $this->actingAs($this->staffUser, 'web');
        $this->postJson('/api/v1/leave-requests', $this->request())->assertCreated();

        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/leave-requests/{$othersId}")->assertForbidden();
        $this->postJson("/api/v1/leave-requests/{$othersId}/cancel")->assertForbidden();

        // Cannot file for someone else.
        $this->postJson('/api/v1/leave-requests', $this->request(['employee_id' => $this->other->id, 'start_date' => '2026-12-20', 'end_date' => '2026-12-20']))
            ->assertForbidden();

        $this->actingAs($this->admin, 'web');
        $this->getJson('/api/v1/leave-requests')->assertJsonCount(2, 'data');
    }

    public function test_admin_files_on_behalf_of_an_employee_including_past_dates(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/v1/leave-requests', $this->request())
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

        $this->postJson('/api/v1/leave-requests', $this->request([
            'employee_id' => $this->other->id, 'start_date' => '2026-11-30', 'end_date' => '2026-11-30',
        ]))->assertCreated()->assertJsonPath('data.employee.id', $this->other->id);
    }

    public function test_users_without_an_employee_record_get_a_clear_error(): void
    {
        $loner = $this->asTenant($this->acme, fn () => User::factory()->create());
        $this->actingAs($loner, 'web');

        $this->postJson('/api/v1/leave-requests', $this->request())
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
    }

    public function test_leave_types_admin_only_and_in_use_types_cannot_be_deleted(): void
    {
        $this->actingAs($this->staffUser, 'web');
        $this->getJson('/api/v1/leave-types')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/leave-types', ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/leave-requests', $this->request())->assertCreated();

        $this->actingAs($this->admin, 'web');
        $this->postJson('/api/v1/leave-types', ['name' => 'Sick', 'days_per_year' => 10])->assertCreated();
        $this->postJson('/api/v1/leave-types', ['name' => 'sick'])->assertUnprocessable();
        $this->deleteJson("/api/v1/leave-types/{$this->annual->id}")->assertStatus(409);

        // Deactivated types disappear for employees and cannot be requested.
        $this->putJson("/api/v1/leave-types/{$this->annual->id}", ['name' => 'Annual', 'days_per_year' => 5, 'is_active' => false])->assertOk();
        $this->actingAs($this->staffUser, 'web');
        $this->getJson('/api/v1/leave-types')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/leave-requests', $this->request(['start_date' => '2026-12-20', 'end_date' => '2026-12-20']))
            ->assertUnprocessable()->assertJsonValidationErrors(['leave_type_id']);
    }

    public function test_dashboard_is_role_specific(): void
    {
        $this->actingAs($this->staffUser, 'web');
        $this->postJson('/api/v1/leave-requests', $this->request())->assertCreated();

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.employee.full_name', 'Mona H')
            ->assertJsonPath('data.balances.0.pending', 3)
            ->assertJsonMissingPath('data.stats');

        $this->actingAs($this->admin, 'web');
        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.stats.employees_total', 2)
            ->assertJsonPath('data.stats.pending_requests', 1);
    }

    public function test_requests_of_another_tenant_are_invisible(): void
    {
        $this->actingAs($this->staffUser, 'web');
        $id = $this->postJson('/api/v1/leave-requests', $this->request())->json('data.id');

        $globex = $this->createTenant('globex');
        config(['tenancy.single_tenant' => 'globex']);
        $globexAdmin = $this->asTenant($globex, fn () => User::factory()->admin()->create());

        $this->actingAs($globexAdmin, 'web');
        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/leave-requests/{$id}")->assertNotFound();
        $this->postJson("/api/v1/leave-requests/{$id}/approve")->assertNotFound();
        $this->postJson('/api/v1/leave-requests', $this->request(['employee_id' => $this->staff->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'leave_type_id']);
    }
}
