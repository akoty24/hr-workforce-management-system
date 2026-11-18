<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

/**
 * Team leader = user with role team_leader whose employee record is the
 * direct manager ("reports to") of other employees. Their decision is final.
 */
class TeamLeaderTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    private User $leaderUser;

    private User $memberUser;

    private User $outsiderUser;

    private Employee $leader;

    private Employee $member;

    private Employee $outsider;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00')); // Wednesday
        config(['hr.weekend_days' => [5, 6]]);

        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->admin = User::factory()->admin()->create();
            $this->leaderUser = User::factory()->create(['role' => UserRole::TeamLeader]);
            $this->memberUser = User::factory()->create();
            $this->outsiderUser = User::factory()->create();

            $this->leader = Employee::create(['employee_code' => 'L1', 'first_name' => 'Ahmed', 'last_name' => 'Ali', 'user_id' => $this->leaderUser->id]);
            $this->member = Employee::create(['employee_code' => 'M1', 'first_name' => 'Mona', 'last_name' => 'Hassan', 'user_id' => $this->memberUser->id, 'manager_id' => $this->leader->id]);
            $this->outsider = Employee::create(['employee_code' => 'O1', 'first_name' => 'Omar', 'last_name' => 'Khaled', 'user_id' => $this->outsiderUser->id]);
            $this->annual = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21]);
        });
    }

    private function submitAs(User $user, string $start = '2026-12-06', string $end = '2026-12-07'): string
    {
        $this->actingAs($user, 'web');

        return $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->annual->id, 'start_date' => $start, 'end_date' => $end,
        ])->assertCreated()->json('data.id');
    }

    public function test_team_leader_approves_their_team_members_request_as_final(): void
    {
        $id = $this->submitAs($this->memberUser);

        $this->actingAs($this->leaderUser, 'web');
        $this->postJson("/api/v1/leave-requests/{$id}/approve", ['note' => 'Have fun'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by', $this->leaderUser->name);
    }

    public function test_team_leader_can_reject_team_requests(): void
    {
        $id = $this->submitAs($this->memberUser);

        $this->actingAs($this->leaderUser, 'web');
        $this->postJson("/api/v1/leave-requests/{$id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_team_leader_cannot_decide_outside_their_team_or_their_own(): void
    {
        $outsiders = $this->submitAs($this->outsiderUser);
        $own = $this->submitAs($this->leaderUser);

        $this->actingAs($this->leaderUser, 'web');
        $this->getJson("/api/v1/leave-requests/{$outsiders}")->assertForbidden();
        $this->postJson("/api/v1/leave-requests/{$outsiders}/approve")->assertForbidden();
        $this->postJson("/api/v1/leave-requests/{$own}/approve")->assertForbidden();

        // Their own request is decided by an admin.
        $this->actingAs($this->admin, 'web');
        $this->postJson("/api/v1/leave-requests/{$own}/approve")->assertOk();
    }

    public function test_admins_can_still_decide_team_requests(): void
    {
        $id = $this->submitAs($this->memberUser);

        $this->actingAs($this->admin, 'web');
        $this->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();

        $this->actingAs($this->leaderUser, 'web');
        $this->postJson("/api/v1/leave-requests/{$id}/reject")->assertStatus(409);
    }

    public function test_team_leader_sees_own_and_team_requests_only(): void
    {
        $this->submitAs($this->memberUser);
        $this->submitAs($this->outsiderUser);
        $this->submitAs($this->leaderUser, '2026-12-13', '2026-12-13');

        $this->actingAs($this->leaderUser, 'web');
        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/leave-requests?scope=team')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $this->member->id);
        $this->getJson('/api/v1/leave-requests?scope=mine')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $this->leader->id);

        // A plain employee asking for the team scope still only gets their own.
        $this->actingAs($this->outsiderUser, 'web');
        $this->getJson('/api/v1/leave-requests?scope=team')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $this->outsider->id);
    }

    public function test_team_leader_sees_team_members_read_only(): void
    {
        $this->actingAs($this->leaderUser, 'web');

        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->member->id);
        $this->getJson("/api/v1/employees/{$this->member->id}")->assertOk()->assertJsonPath('data.manager.id', $this->leader->id);
        $this->getJson("/api/v1/employees/{$this->outsider->id}")->assertForbidden();
        $this->getJson("/api/v1/leave-balances?employee_id={$this->member->id}")->assertOk();
        $this->getJson("/api/v1/leave-balances?employee_id={$this->outsider->id}")->assertForbidden();

        $this->putJson("/api/v1/employees/{$this->member->id}", ['employee_code' => 'M1', 'first_name' => 'X', 'last_name' => 'Y', 'status' => 'active'])->assertForbidden();
        $this->postJson('/api/v1/employees', [])->assertForbidden();
    }

    public function test_a_team_leader_without_reports_sees_nothing_extra(): void
    {
        $lonely = $this->asTenant($this->acme, fn () => User::factory()->create(['role' => UserRole::TeamLeader]));
        $this->submitAs($this->memberUser);

        $this->actingAs($lonely, 'web');
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_team_leader_dashboard_shows_the_team(): void
    {
        $this->submitAs($this->memberUser);

        $this->actingAs($this->leaderUser, 'web');
        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'team_leader')
            ->assertJsonPath('data.team.size', 1)
            ->assertJsonCount(1, 'data.team.pending_requests');

        $this->actingAs($this->memberUser, 'web');
        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.employee.manager', 'Ahmed Ali')
            ->assertJsonMissingPath('data.team');
    }

    public function test_reporting_lines_cannot_form_a_cycle(): void
    {
        $this->actingAs($this->admin, 'web');
        $base = ['first_name' => 'Ahmed', 'last_name' => 'Ali', 'status' => 'active'];

        // Ahmed reports to Mona, who reports to Ahmed -> cycle.
        $this->putJson("/api/v1/employees/{$this->leader->id}", $base + ['employee_code' => 'L1', 'manager_id' => $this->member->id])
            ->assertUnprocessable()->assertJsonPath('errors.manager_id.0', 'This would create a circular reporting line.');

        $this->putJson("/api/v1/employees/{$this->leader->id}", $base + ['employee_code' => 'L1', 'manager_id' => $this->leader->id])
            ->assertUnprocessable()->assertJsonPath('errors.manager_id.0', 'An employee cannot report to themselves.');

        $this->putJson("/api/v1/employees/{$this->leader->id}", $base + ['employee_code' => 'L1', 'manager_id' => $this->outsider->id])
            ->assertOk()->assertJsonPath('data.manager.full_name', 'Omar Khaled');
    }

    public function test_managers_with_reports_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->deleteJson("/api/v1/employees/{$this->leader->id}")->assertStatus(409);
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($this->leader->id));
        $this->assertSame(0, LeaveRequest::withoutGlobalScopes()->count());
    }
}
