<?php

namespace Tests\Feature\Tenancy;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\Fixtures\Models\Widget;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'tenant'])->group(function () {
            Route::get('/__test/tenant', fn (TenantContext $context) => $context->current()->slug);
            Route::get('/__test/widgets/{widget}', fn (Widget $widget) => $widget->name);
        });
    }

    private function useSubdomains(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);
    }

    // --- subdomain resolver (SaaS) ----------------------------------------

    public function test_subdomain_resolves_the_matching_tenant(): void
    {
        $this->useSubdomains();
        $this->createTenant('acme');
        $this->createTenant('globex');

        $this->get('http://acme.example.test/__test/tenant')->assertOk()->assertContent('acme');
        $this->get('http://globex.example.test/__test/tenant')->assertOk()->assertContent('globex');
    }

    public function test_subdomain_matching_is_case_insensitive(): void
    {
        $this->useSubdomains();
        $this->createTenant('acme');

        $this->get('http://ACME.Example.Test/__test/tenant')->assertOk()->assertContent('acme');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unresolvableHosts(): array
    {
        return [
            'unknown tenant' => ['http://nobody.example.test/__test/tenant'],
            'bare base domain' => ['http://example.test/__test/tenant'],
            'nested subdomain' => ['http://x.acme.example.test/__test/tenant'],
            'reserved slug' => ['http://www.example.test/__test/tenant'],
            'foreign domain' => ['http://acme.evil.test/__test/tenant'],
            'look-alike suffix' => ['http://acme.notexample.test/__test/tenant'],
        ];
    }

    #[DataProvider('unresolvableHosts')]
    public function test_unresolvable_hosts_fail_closed_with_404(string $url): void
    {
        $this->useSubdomains();
        $this->createTenant('acme');

        $this->get($url)->assertNotFound();
    }

    public function test_suspended_tenant_gets_403(): void
    {
        $this->useSubdomains();
        $this->createTenant('acme', TenantStatus::Suspended);

        $this->get('http://acme.example.test/__test/tenant')->assertForbidden();
    }

    public function test_route_model_binding_cannot_load_another_tenants_record(): void
    {
        $this->useSubdomains();
        $acme = $this->createTenant('acme');
        $globex = $this->createTenant('globex');
        $globexWidget = $this->asTenant($globex, fn () => Widget::create(['name' => 'Globex secret']));
        $this->asTenant($acme, fn () => Widget::create(['name' => 'Acme widget']));

        $this->get("http://globex.example.test/__test/widgets/{$globexWidget->id}")->assertOk()->assertContent('Globex secret');
        $this->get("http://acme.example.test/__test/widgets/{$globexWidget->id}")->assertNotFound();
    }

    public function test_context_from_a_previous_request_is_not_inherited(): void
    {
        $this->useSubdomains();
        $this->createTenant('acme');

        $this->get('http://acme.example.test/__test/tenant')->assertOk();
        $this->get('http://nobody.example.test/__test/tenant')->assertNotFound();

        $this->assertFalse($this->tenantContext()->check());
    }

    // --- single resolver (on-prem / single domain) -------------------------

    public function test_single_resolver_uses_the_only_tenant(): void
    {
        $this->createTenant('acme');

        $this->get('/__test/tenant')->assertOk()->assertContent('acme');
    }

    public function test_single_resolver_fails_closed_with_no_tenant(): void
    {
        $this->get('/__test/tenant')->assertNotFound();
    }

    public function test_single_resolver_fails_closed_with_several_tenants_unless_pinned(): void
    {
        $this->createTenant('acme');
        $this->createTenant('globex');

        $this->get('/__test/tenant')->assertNotFound();

        config(['tenancy.single_tenant' => 'globex']);

        $this->get('/__test/tenant')->assertOk()->assertContent('globex');
    }

    // --- configuration ------------------------------------------------------

    public function test_subdomain_resolver_requires_a_base_domain(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => null]);
        $this->withoutExceptionHandling();

        $this->expectExceptionMessage('TENANCY_BASE_DOMAIN must be set');

        $this->get('/__test/tenant');
    }

    public function test_unknown_resolver_is_rejected(): void
    {
        config(['tenancy.resolver' => 'header']);
        $this->withoutExceptionHandling();

        $this->expectExceptionMessage('Unknown TENANCY_RESOLVER');

        $this->get('/__test/tenant');
    }
}
