<?php

namespace Tests\Feature;

use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class SystemCheckTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    public function test_it_fails_without_a_tenant_and_passes_with_one(): void
    {
        $this->artisan('system:check')->expectsOutputToContain('tenant:install')->assertFailed();

        $this->createTenant('acme');

        $this->artisan('system:check')->assertSuccessful();
    }

    public function test_it_fails_on_an_invalid_resolver_configuration(): void
    {
        $this->createTenant('acme');
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => null]);

        $this->artisan('system:check')->assertFailed();
    }
}
