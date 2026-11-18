<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantStatus;

trait CreatesTenants
{
    protected function createTenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug).' Ltd', 'status' => $status]);
    }

    protected function tenantContext(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    /**
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return $this->tenantContext()->run($tenant, $callback);
    }
}
