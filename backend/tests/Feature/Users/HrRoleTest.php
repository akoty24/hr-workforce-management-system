<?php

namespace Tests\Feature\Users;

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
 * HR manages company HR data and decides every request, but only admins
 * manage user accounts and roles.
 */
class HrRoleTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $hr;

    private User $staffUser;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00'));
        config(['hr.weekend_days' => [5, 6]]);
        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->hr = User::factory()->create(['role' => UserRole::Hr]);
            $this->staffUser = User::factory()->create();
            Employee::create(['employee_code' => 'E1', 'first_name' => 'Mona', 'last_name' => 'H', 'user_id' => $this->staffUser->id]);
            $this->annual = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21]);
        });
    }

    public function test_hr_manages_employees_departments_and_leave_types(): void
    {
        $this->actingAs($this->hr, 'web');

        $this->postJson('/api/v1/departments', ['name' => 'Ops'])->assertCreated();
        $this->postJson('/api/v1/employees', ['employee_code' => 'E2', 'first_name' => 'Omar', 'last_name' => 'K', 'status' => 'active'])
            ->assertCreated();
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonPath('meta.total', 2);
        $this->postJson('/api/v1/leave-types', ['name' => 'Sick', 'days_per_year' => 15])->assertCreated();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.role', 'overview');
    }

    public function test_hr_decides_any_request(): void
    {
        $this->actingAs($this->staffUser, 'web');
        $leave = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->annual->id, 'start_date' => '2026-12-06', 'end_date' => '2026-12-07',
        ])->assertCreated()->json('data.id');
        $permission = $this->postJson('/api/v1/short-leaves', ['date' => '2026-12-03', 'start_time' => '09:00', 'end_time' => '10:00'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->hr, 'web');
        $this->postJson("/api/v1/leave-requests/{$leave}/approve")->assertOk();
        $this->postJson("/api/v1/short-leaves/{$permission}/reject")->assertOk();
    }

    public function test_hr_lists_accounts_but_cannot_create_or_change_them(): void
    {
        $this->actingAs($this->hr, 'web');

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'Password12345', 'role' => 'admin',
        ])->assertForbidden();
        $this->putJson("/api/v1/users/{$this->hr->id}", [
            'name' => $this->hr->name, 'email' => $this->hr->email, 'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_admin_can_assign_the_hr_role(): void
    {
        $admin = $this->asTenant($this->acme, fn () => User::factory()->admin()->create());
        $this->actingAs($admin, 'web');

        $this->putJson("/api/v1/users/{$this->staffUser->id}", [
            'name' => $this->staffUser->name, 'email' => $this->staffUser->email, 'role' => 'hr',
        ])->assertOk()->assertJsonPath('data.role', 'hr');
    }
}
