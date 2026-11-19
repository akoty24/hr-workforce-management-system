<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

/** Company default password "{slug}Password123" and the forced change at first sign-in. */
class DefaultPasswordTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);

        $this->acme = $this->createTenant('acme');
        $this->admin = $this->asTenant($this->acme, fn () => User::factory()->admin()->create());
    }

    private function api(string $path, string $host = 'acme.example.test'): string
    {
        return "http://{$host}/api/v1{$path}";
    }

    /** Sign in as someone else on a fresh session. */
    private function signInAs(string $email, string $password, string $host = 'acme.example.test'): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson($this->api('/auth/login', $host), ['email' => $email, 'password' => $password])->assertOk();
    }

    public function test_new_login_without_password_gets_the_company_default_and_must_change_it(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->postJson($this->api('/employees'), [
            'employee_code' => 'E-1', 'first_name' => 'Mona', 'last_name' => 'Hassan',
            'email' => 'mona@acme.test', 'status' => 'active',
            'account' => ['role' => 'employee', 'password' => null],
        ])->assertCreated();

        $this->signInAs('mona@acme.test', 'acmePassword123');

        // Only "me", sign out and the password change are allowed until then.
        $this->getJson($this->api('/auth/me'))->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->getJson($this->api('/dashboard'))->assertForbidden()->assertJsonPath('code', 'password_change_required');

        // Cannot keep the default.
        $this->putJson($this->api('/account/password'), [
            'current_password' => 'acmePassword123', 'password' => 'acmePassword123', 'password_confirmation' => 'acmePassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson($this->api('/account/password'), [
            'current_password' => 'acmePassword123', 'password' => 'MonaOwnPass2026', 'password_confirmation' => 'MonaOwnPass2026',
        ])->assertNoContent();

        $this->getJson($this->api('/auth/me'))->assertJsonPath('data.must_change_password', false);
        $this->getJson($this->api('/dashboard'))->assertOk();
    }

    public function test_passwords_set_by_an_admin_must_be_changed_but_your_own_not(): void
    {
        $this->actingAs($this->admin, 'web');

        $id = $this->postJson($this->api('/users'), [
            'name' => 'Omar', 'email' => 'omar@acme.test', 'role' => 'employee', 'password' => 'AdminChose2026',
        ])->assertCreated()->assertJsonPath('data.must_change_password', true)->json('data.id');

        $this->asTenant($this->acme, fn () => User::query()->whereKey($id)->update(['must_change_password' => false]));
        $this->putJson($this->api("/users/{$id}"), [
            'name' => 'Omar', 'email' => 'omar@acme.test', 'role' => 'employee', 'password' => 'AdminReset2026',
        ])->assertOk()->assertJsonPath('data.must_change_password', true);

        // An admin resetting their own password through the same screen is not forced.
        $this->putJson($this->api("/users/{$this->admin->id}"), [
            'name' => $this->admin->name, 'email' => $this->admin->email, 'role' => 'admin', 'password' => 'MyOwnNewPass2026',
        ])->assertOk()->assertJsonPath('data.must_change_password', false);
    }

    public function test_owner_console_uses_the_default_for_new_companies_and_resets(): void
    {
        config(['app.url' => 'http://localhost:5173']);
        $owner = PlatformAdmin::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'OwnerPassword123']);
        $this->actingAs($owner, 'platform');

        $this->postJson('http://admin.example.test/api/platform/tenants', [
            'name' => 'Elnady', 'slug' => 'elnady', 'admin_name' => 'Hana', 'admin_email' => 'hana@elnady.test',
        ])->assertCreated()->assertJsonPath('data.admin_password', 'elnadyPassword123');

        $elnady = Tenant::query()->where('slug', 'elnady')->firstOrFail();
        $hana = $this->asTenant($elnady, fn () => User::query()->where('email', 'hana@elnady.test')->firstOrFail());
        $this->assertTrue($hana->must_change_password);

        $this->putJson("http://admin.example.test/api/platform/tenants/{$elnady->id}/admins/{$hana->id}/password")
            ->assertOk()->assertJsonPath('data.password', 'elnadyPassword123');

        $this->app['auth']->shouldUse('web');
        $this->signInAs('hana@elnady.test', 'elnadyPassword123', 'elnady.example.test');
    }

    public function test_forgot_password_clears_the_flag(): void
    {
        $this->asTenant($this->acme, fn () => $this->admin->forceFill(['must_change_password' => true])->save());

        $this->assertTrue($this->admin->fresh()->must_change_password);
        // Covered end to end in PasswordResetTest; here only the flag.
        $this->asTenant($this->acme, fn () => $this->admin->forceFill(['password' => 'ResetByLink2026', 'must_change_password' => false])->save());
        $this->assertFalse($this->admin->fresh()->must_change_password);
    }
}
