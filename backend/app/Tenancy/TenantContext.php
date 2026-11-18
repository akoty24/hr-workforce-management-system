<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContextException;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Holds the tenant for the current unit of work (HTTP request, queued job,
 * console command).
 *
 * Registered as a *scoped* binding: the queue worker discards it between jobs,
 * so a tenant can never carry over from one job to the next.
 *
 * Set by:
 *  - ResolveTenant middleware (HTTP)
 *  - the queue hook in TenancyServiceProvider (jobs carry tenant_id)
 *  - run() for explicit work on behalf of a tenant (commands, provisioning, tests)
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * @throws NoTenantContextException
     */
    public function current(): Tenant
    {
        return $this->tenant ?? throw new NoTenantContextException;
    }

    /**
     * @throws NoTenantContextException
     */
    public function id(): string
    {
        return $this->current()->getKey();
    }

    /**
     * Run $callback as $tenant, restoring the previous context afterwards
     * (also when the callback throws).
     *
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            $result = $callback($tenant);

            // Job::dispatch() returns a PendingDispatch that only queues the job
            // when destroyed. If the callback returns it (e.g. fn () => Job::dispatch()),
            // release it here, while this tenant is still in context; otherwise the
            // job would be queued after the context is restored, without its tenant.
            if ($result instanceof PendingDispatch) {
                $result = null;
            }

            return $result;
        } finally {
            $this->tenant = $previous;
        }
    }
}
