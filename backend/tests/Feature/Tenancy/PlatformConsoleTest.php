<?php

namespace Tests\Feature\Tenancy;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private PlatformAdmin $owner;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.resolver' => 'subdomain',
            'tenancy.base_domain' => 'example.test',
            'app.url' => 'http://localhost:5173',
        ]);

        $this->owner = PlatformAdmin::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'OwnerPassword123']);
        $this->acme = $this->createTenant('acme');
    }

    private function url(string $path, string $host = 'admin.example.test'): string
    {
        return "http://{$host}/api/platform{$path}";
    }

    private function newCompany(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Globex Corp',
            'slug' => 'globex',
            'admin_name' => 'Hana',
            'admin_email' => 'Hana@Globex.test',
        ];
    }

    public function test_console_exists_only_on_the_platform_host(): void
    {
        $this->getJson($this->url('/me', 'acme.example.test'))->assertNotFound();
        $this->postJson($this->url('/login', 'acme.example.test'), ['email' => 'owner@example.com', 'password' => 'OwnerPassword123'])
            ->assertNotFound();

        // Disabled entirely outside subdomain mode unless a host is configured.
        config(['tenancy.resolver' => 'single']);
        $this->getJson($this->url('/me'))->assertNotFound();
    }

    public function test_owner_signs_in_and_company_users_cannot(): void
    {
        $this->getJson($this->url('/me'))->assertUnauthorized();
        $this->getJson($this->url('/tenants'))->assertUnauthorized();

        $companyAdmin = $this->asTenant($this->acme, fn () => User::factory()->admin()->create(['password' => 'CompanyPass123']));
        $this->postJson($this->url('/login'), ['email' => $companyAdmin->email, 'password' => 'CompanyPass123'])
            ->assertUnprocessable();

        // A company session does not open the console.
        $this->actingAs($companyAdmin, 'web');
        $this->getJson($this->url('/tenants'))->assertUnauthorized();

        $this->postJson($this->url('/login'), ['email' => 'OWNER@example.com', 'password' => 'OwnerPassword123'])
            ->assertOk()->assertJsonPath('data.email', 'owner@example.com');
        $this->getJson($this->url('/me'))->assertOk();
    }

    public function test_owner_creates_a_company_with_its_first_admin(): void
    {
        $this->actingAs($this->owner, 'platform');

        $response = $this->postJson($this->url('/tenants'), $this->newCompany())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'globex')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.url', 'http://globex.example.test:5173')
            ->assertJsonPath('data.admin_email', 'hana@globex.test');

        $password = $response->json('data.admin_password');
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(12, strlen($password));

        $globex = Tenant::query()->where('slug', 'globex')->firstOrFail();
        $this->asTenant($globex, function () use ($password) {
            $admin = User::query()->where('email', 'hana@globex.test')->firstOrFail();
            $this->assertSame(UserRole::Admin, $admin->role);
            $this->assertTrue(Hash::check($password, $admin->password));
        });

        // The new admin can sign in on the company's own address.
        // (actingAs() above switched the test's default guard; a real request starts on "web".)
        $this->app['auth']->shouldUse('web');
        $this->postJson('http://globex.example.test/api/v1/auth/login', ['email' => 'hana@globex.test', 'password' => $password])
            ->assertOk();

        $this->getJson($this->url('/tenants'))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.slug', 'globex')
            ->assertJsonPath('data.1.users_count', 1);
    }

    public function test_chosen_password_is_not_echoed_and_bad_input_is_rejected(): void
    {
        $this->actingAs($this->owner, 'platform');

        $this->postJson($this->url('/tenants'), $this->newCompany(['admin_password' => 'Chosen-pass-2026']))
            ->assertCreated()->assertJsonPath('data.admin_password', null);

        $this->postJson($this->url('/tenants'), $this->newCompany(['slug' => 'acme']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->url('/tenants'), $this->newCompany(['slug' => 'admin']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->url('/tenants'), $this->newCompany(['slug' => 'Bad Slug!']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->url('/tenants'), $this->newCompany(['slug' => 'other', 'admin_email' => 'nope']))
            ->assertUnprocessable()->assertJsonValidationErrors('admin_email');
        $this->postJson($this->url('/tenants'), $this->newCompany(['slug' => 'other', 'admin_password' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('admin_password');

        $this->assertSame(0, Tenant::query()->where('slug', 'other')->count());
    }

    public function test_owner_suspends_and_reactivates_a_company(): void
    {
        $this->actingAs($this->owner, 'platform');

        $this->patchJson($this->url("/tenants/{$this->acme->id}"), ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->getJson('http://acme.example.test/api/v1/tenant')->assertForbidden();

        $this->patchJson($this->url("/tenants/{$this->acme->id}"), ['status' => 'active', 'name' => 'Acme Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Acme Renamed');
        $this->getJson('http://acme.example.test/api/v1/tenant')->assertOk()->assertJsonPath('data.name', 'Acme Renamed');
    }

    public function test_owner_changes_a_company_address(): void
    {
        $this->createTenant('globex');
        $this->actingAs($this->owner, 'platform');

        foreach (['globex', 'admin', 'Bad Address', 'ab'] as $taken) {
            $this->patchJson($this->url("/tenants/{$this->acme->id}"), ['slug' => $taken])
                ->assertUnprocessable()->assertJsonValidationErrors('slug');
        }

        // Keeping the same address is fine.
        $this->patchJson($this->url("/tenants/{$this->acme->id}"), ['slug' => 'acme', 'name' => 'Acme'])->assertOk();

        $this->patchJson($this->url("/tenants/{$this->acme->id}"), ['slug' => ' Acme-Egypt '])
            ->assertOk()
            ->assertJsonPath('data.slug', 'acme-egypt')
            ->assertJsonPath('data.url', 'http://acme-egypt.example.test:5173');

        // The company moves: new address works, old one is gone.
        $this->getJson('http://acme-egypt.example.test/api/v1/tenant')->assertOk();
        $this->getJson('http://acme.example.test/api/v1/tenant')->assertNotFound();
    }

    public function test_owner_lists_company_admins_and_resets_a_password(): void
    {
        [$admin, $employee] = $this->asTenant($this->acme, fn () => [
            User::factory()->admin()->create(['name' => 'Acme Admin']),
            User::factory()->create(),
        ]);
        $other = $this->createTenant('globex');
        $globexAdmin = $this->asTenant($other, fn () => User::factory()->admin()->create());

        $this->getJson($this->url("/tenants/{$this->acme->id}/admins"))->assertUnauthorized();
        $this->actingAs($this->owner, 'platform');

        $this->getJson($this->url("/tenants/{$this->acme->id}/admins"))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $admin->email);

        $password = $this->putJson($this->url("/tenants/{$this->acme->id}/admins/{$admin->id}/password"))
            ->assertOk()->json('data.password');
        $this->assertTrue(Hash::check($password, $admin->fresh()->password));

        $this->putJson($this->url("/tenants/{$this->acme->id}/admins/{$admin->id}/password"), ['password' => 'Chosen-pass-2026'])
            ->assertOk()->assertJsonPath('data.password', null);
        $this->assertTrue(Hash::check('Chosen-pass-2026', $admin->fresh()->password));

        // Only administrators, and only of that company.
        $this->putJson($this->url("/tenants/{$this->acme->id}/admins/{$employee->id}/password"))->assertNotFound();
        $this->putJson($this->url("/tenants/{$this->acme->id}/admins/{$globexAdmin->id}/password"))->assertNotFound();
    }

    public function test_owner_changes_password(): void
    {
        $this->actingAs($this->owner, 'platform');

        $this->putJson($this->url('/password'), [
            'current_password' => 'wrong', 'password' => 'NewOwnerPass456', 'password_confirmation' => 'NewOwnerPass456',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson($this->url('/password'), [
            'current_password' => 'OwnerPassword123', 'password' => 'NewOwnerPass456', 'password_confirmation' => 'NewOwnerPass456',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('NewOwnerPass456', $this->owner->fresh()->password));
    }

    public function test_command_creates_the_owner_account(): void
    {
        $this->artisan('platform:admin', ['email' => 'Boss@Example.com', '--password' => 'BossPassword123'])->assertSuccessful();

        $this->assertTrue(Hash::check('BossPassword123', PlatformAdmin::query()->where('email', 'boss@example.com')->value('password')));
    }
}
