<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

/**
 * Hourly permissions: one working day, from/to time, max 2 hours,
 * approved by an admin or the direct team leader.
 */
class ShortLeaveTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    private User $leaderUser;

    private User $memberUser;

    private User $outsiderUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00')); // Wednesday
        config(['hr.weekend_days' => [5, 6], 'hr.short_leave_max_minutes' => 120]);

        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->admin = User::factory()->admin()->create();
            $this->leaderUser = User::factory()->create(['role' => UserRole::TeamLeader]);
            $this->memberUser = User::factory()->create();
            $this->outsiderUser = User::factory()->create();

            $leader = Employee::create(['employee_code' => 'L1', 'first_name' => 'Ahmed', 'last_name' => 'Ali', 'user_id' => $this->leaderUser->id]);
            Employee::create(['employee_code' => 'M1', 'first_name' => 'Mona', 'last_name' => 'Hassan', 'user_id' => $this->memberUser->id, 'manager_id' => $leader->id]);
            Employee::create(['employee_code' => 'O1', 'first_name' => 'Omar', 'last_name' => 'Khaled', 'user_id' => $this->outsiderUser->id]);
        });
    }

    private function permission(array $overrides = []): array
    {
        return $overrides + ['date' => '2026-12-03', 'start_time' => '10:15', 'end_time' => '12:00', 'reason' => 'Bank'];
    }

    public function test_employee_requests_and_team_leader_approves(): void
    {
        $this->actingAs($this->memberUser, 'web');

        $id = $this->postJson('/api/v1/short-leaves', $this->permission())
            ->assertCreated()
            ->assertJsonPath('data.start_time', '10:15')
            ->assertJsonPath('data.end_time', '12:00')
            ->assertJsonPath('data.minutes', 105)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->postJson("/api/v1/short-leaves/{$id}/approve")->assertForbidden();

        $this->actingAs($this->outsiderUser, 'web');
        $this->getJson("/api/v1/short-leaves/{$id}")->assertForbidden();
        $this->getJson('/api/v1/short-leaves')->assertJsonCount(0, 'data');

        $this->actingAs($this->leaderUser, 'web');
        $this->getJson('/api/v1/short-leaves?scope=team')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/short-leaves/{$id}/approve", ['note' => 'OK'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($this->admin, 'web');
        $this->postJson("/api/v1/short-leaves/{$id}/reject")->assertStatus(409);
    }

    public function test_rules(): void
    {
        $this->actingAs($this->memberUser, 'web');

        // Longer than 2 hours.
        $this->postJson('/api/v1/short-leaves', $this->permission(['start_time' => '09:00', 'end_time' => '11:01']))
            ->assertUnprocessable()->assertJsonValidationErrors('end_time');

        // End before start.
        $this->postJson('/api/v1/short-leaves', $this->permission(['start_time' => '12:00', 'end_time' => '11:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('end_time');

        // Bad time format.
        $this->postJson('/api/v1/short-leaves', $this->permission(['start_time' => '9am']))
            ->assertUnprocessable()->assertJsonValidationErrors('start_time');

        // Weekend (Friday 4 Dec).
        $this->postJson('/api/v1/short-leaves', $this->permission(['date' => '2026-12-04']))
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        // Past date.
        $this->postJson('/api/v1/short-leaves', $this->permission(['date' => '2026-12-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        // Exactly 2 hours is fine; an overlapping one is not; an adjacent one is.
        $this->postJson('/api/v1/short-leaves', $this->permission(['start_time' => '09:00', 'end_time' => '11:00']))->assertCreated();
        $this->postJson('/api/v1/short-leaves', $this->permission(['start_time' => '10:30', 'end_time' => '11:30']))
            ->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->postJson('/api/v1/short-leaves', $this->permission(['start_time' => '11:00', 'end_time' => '11:30']))->assertCreated();
    }

    public function test_cannot_request_a_permission_on_a_leave_day(): void
    {
        $type = $this->asTenant($this->acme, fn () => LeaveType::create(['name' => 'Annual', 'days_per_year' => 21]));
        $this->actingAs($this->memberUser, 'web');

        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $type->id, 'start_date' => '2026-12-03', 'end_date' => '2026-12-03'])
            ->assertCreated();

        $this->postJson('/api/v1/short-leaves', $this->permission())
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_owner_cancels_and_leader_cannot_approve_own(): void
    {
        $this->actingAs($this->leaderUser, 'web');
        $own = $this->postJson('/api/v1/short-leaves', $this->permission())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/short-leaves/{$own}/approve")->assertForbidden();
        $this->postJson("/api/v1/short-leaves/{$own}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_other_tenants_cannot_see_permissions(): void
    {
        $this->actingAs($this->memberUser, 'web');
        $id = $this->postJson('/api/v1/short-leaves', $this->permission())->assertCreated()->json('data.id');

        $globex = $this->createTenant('globex');
        config(['tenancy.single_tenant' => 'globex']);
        $globexAdmin = $this->asTenant($globex, fn () => User::factory()->admin()->create());

        $this->actingAs($globexAdmin, 'web');
        $this->getJson('/api/v1/short-leaves')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/short-leaves/{$id}")->assertNotFound();
        $this->postJson("/api/v1/short-leaves/{$id}/approve")->assertNotFound();
    }
}
