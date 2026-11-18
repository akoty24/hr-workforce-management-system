<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class InstallTenantCommandTest extends TestCase
{
    use RefreshTestDatabase;

    private function install(array $options): PendingCommand
    {
        return $this->artisan('tenant:install', $options + [
            '--slug' => 'acme',
            '--name' => 'Acme Ltd',
            '--admin-name' => 'Sara Admin',
            '--admin-email' => 'Sara@Acme.test',
        ]);
    }

    public function test_it_creates_the_tenant_and_an_admin(): void
    {
        $this->install(['--admin-password' => 'StrongPassw0rd!'])->assertSuccessful();

        $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
        $admin = User::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertSame('sara@acme.test', $admin->email);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('StrongPassw0rd!', $admin->password));
    }

    public function test_it_generates_a_password_when_none_is_given(): void
    {
        $this->install([])
            ->expectsOutputToContain('Admin password')
            ->assertSuccessful();
    }

    public function test_weak_passwords_are_rejected_and_nothing_is_created(): void
    {
        $this->install(['--admin-password' => 'short'])->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_single_tenant_mode_refuses_a_second_install(): void
    {
        $this->install(['--admin-password' => 'StrongPassw0rd!'])->assertSuccessful();

        $this->install(['--slug' => 'globex', '--admin-password' => 'StrongPassw0rd!'])->assertFailed();

        $this->assertDatabaseCount('tenants', 1);
    }
}
