<?php

namespace Tests\Feature\Users;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->createTenant('acme');
        [$this->admin, $this->employee] = $this->asTenant($this->acme, fn () => [
            User::factory()->admin()->create(['password' => 'AdminPassw0rd!']),
            User::factory()->create(['email' => 'emp@acme.test', 'password' => 'EmpPassw0rd123']),
        ]);
    }

    public function test_only_admins_manage_users(): void
    {
        $this->actingAs($this->employee, 'web');

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
    }

    public function test_admin_creates_updates_and_resets_passwords(): void
    {
        $this->actingAs($this->admin, 'web');

        $id = $this->postJson('/api/v1/users', [
            'name' => 'New Person', 'email' => ' New@Acme.test ', 'role' => 'employee', 'password' => 'Welcome12345',
        ])->assertCreated()->assertJsonPath('data.email', 'new@acme.test')->json('data.id');

        $this->putJson("/api/v1/users/{$id}", [
            'name' => 'New Person', 'email' => 'new@acme.test', 'role' => 'admin', 'password' => 'Changed12345',
        ])->assertOk()->assertJsonPath('data.role', 'admin');

        $user = User::withoutGlobalScope(TenantScope::class)->find($id);
        $this->assertTrue(Hash::check('Changed12345', $user->password));

        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_email_must_be_unique_within_the_tenant(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/v1/users', [
            'name' => 'Dup', 'email' => 'emp@acme.test', 'role' => 'employee', 'password' => 'Welcome12345',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->putJson("/api/v1/users/{$this->admin->id}", [
            'name' => 'Me', 'email' => $this->admin->email, 'role' => 'employee',
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->putJson("/api/v1/users/{$this->admin->id}", [
            'name' => 'Me', 'email' => $this->admin->email, 'role' => 'admin', 'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
    }

    public function test_deactivated_users_cannot_log_in_and_lose_their_session(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->putJson("/api/v1/users/{$this->employee->id}", [
            'name' => $this->employee->name, 'email' => 'emp@acme.test', 'role' => 'employee', 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', ['email' => 'emp@acme.test', 'password' => 'EmpPassw0rd123'])
            ->assertUnprocessable();

        // An existing session of the now-inactive user is rejected too.
        $this->actingAs($this->employee->fresh(), 'web');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_users_change_their_own_password(): void
    {
        $this->actingAs($this->employee, 'web');

        $this->putJson('/api/v1/account/password', [
            'current_password' => 'wrong', 'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->putJson('/api/v1/account/password', [
            'current_password' => 'EmpPassw0rd123', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->putJson('/api/v1/account/password', [
            'current_password' => 'EmpPassw0rd123', 'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('BrandNew12345', $this->employee->fresh()->password));
    }

    public function test_admins_of_another_tenant_cannot_touch_these_users(): void
    {
        $globex = $this->createTenant('globex');
        config(['tenancy.single_tenant' => 'globex']);
        $globexAdmin = $this->asTenant($globex, fn () => User::factory()->admin()->create());

        $this->actingAs($globexAdmin, 'web');

        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/users/{$this->employee->id}", [
            'name' => 'X', 'email' => 'x@x.test', 'role' => 'admin',
        ])->assertNotFound();
    }
}
