<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantStatus;
use Database\Seeders\DatabaseSeeder;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private Tenant $globex;

    private User $acmeUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');
        $this->acmeUser = $this->asTenant($this->acme, fn () => User::factory()->create([
            'email' => 'sara@acme.test',
            'password' => 'CorrectHorse123',
        ]));
    }

    private function url(string $tenant, string $path): string
    {
        return "http://{$tenant}.example.test/api/v1{$path}";
    }

    public function test_tenant_endpoint_is_public_and_issues_a_csrf_cookie(): void
    {
        $this->getJson($this->url('acme', '/tenant'))
            ->assertOk()
            ->assertExactJson(['data' => ['name' => 'Acme Ltd', 'slug' => 'acme']])
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_user_can_log_in_and_fetch_their_profile(): void
    {
        $this->postJson($this->url('acme', '/auth/login'), ['email' => 'Sara@Acme.test', 'password' => 'CorrectHorse123'])
            ->assertOk()
            ->assertJsonPath('data.email', 'sara@acme.test')
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonMissingPath('data.password');

        $this->getJson($this->url('acme', '/auth/me'))->assertOk()->assertJsonPath('data.id', $this->acmeUser->id);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson($this->url('acme', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_a_user_cannot_log_in_to_another_tenant(): void
    {
        // Correct credentials, wrong workspace.
        $this->postJson($this->url('globex', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'CorrectHorse123'])
            ->assertUnprocessable();

        $this->assertGuest('web');
    }

    public function test_the_same_email_can_exist_in_two_tenants_independently(): void
    {
        $this->asTenant($this->globex, fn () => User::factory()->create([
            'email' => 'sara@acme.test',
            'password' => 'GlobexOnly12345',
        ]));

        $this->postJson($this->url('globex', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'CorrectHorse123'])
            ->assertUnprocessable();

        $this->postJson($this->url('globex', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'GlobexOnly12345'])
            ->assertOk();
    }

    public function test_an_authenticated_session_is_not_valid_on_another_tenant(): void
    {
        $this->actingAs($this->acmeUser, 'web');

        $this->getJson($this->url('globex', '/auth/me'))->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson($this->url('acme', '/auth/me'))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->postJson($this->url('acme', '/auth/logout'))->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->acmeUser, 'web');

        $this->postJson($this->url('acme', '/auth/logout'))->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('acme', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'nope'])->assertUnprocessable();
        }

        $this->postJson($this->url('acme', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'CorrectHorse123'])
            ->assertStatus(429);

        // Limits are per tenant: the same email on another tenant is unaffected.
        $this->postJson($this->url('globex', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'nope'])
            ->assertUnprocessable();
    }

    public function test_suspended_tenant_cannot_log_in(): void
    {
        $this->acme->update(['status' => TenantStatus::Suspended]);

        $this->postJson($this->url('acme', '/auth/login'), ['email' => 'sara@acme.test', 'password' => 'CorrectHorse123'])
            ->assertForbidden();
    }

    public function test_login_validation(): void
    {
        $this->postJson($this->url('acme', '/auth/login'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_demo_seeder_creates_working_accounts(): void
    {
        config(['tenancy.resolver' => 'single', 'tenancy.single_tenant' => 'demo']);
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/v1/auth/login', ['email' => DatabaseSeeder::DEMO_ADMIN_EMAIL, 'password' => DatabaseSeeder::DEMO_PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }
}
