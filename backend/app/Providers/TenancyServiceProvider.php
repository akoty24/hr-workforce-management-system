<?php

namespace App\Providers;

use App\Models\Tenant;
use App\Tenancy\Resolvers\SingleTenantResolver;
use App\Tenancy\Resolvers\SubdomainTenantResolver;
use App\Tenancy\Resolvers\TenantResolver;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: discarded between queued jobs (and requests under long-lived workers).
        $this->app->scoped(TenantContext::class);

        $this->app->bind(TenantResolver::class, function () {
            return match (config('tenancy.resolver')) {
                'single' => new SingleTenantResolver(config('tenancy.single_tenant')),
                'subdomain' => new SubdomainTenantResolver(
                    config('tenancy.base_domain') ?: throw new RuntimeException('TENANCY_BASE_DOMAIN must be set when TENANCY_RESOLVER=subdomain.'),
                ),
                default => throw new InvalidArgumentException('Unknown TENANCY_RESOLVER ['.config('tenancy.resolver').']; expected "single" or "subdomain".'),
            };
        });
    }

    public function boot(): void
    {
        $this->propagateTenantThroughQueue();
        $this->registerSchemaMacros();
    }

    /**
     * Every queued job (jobs, queued mail/notifications/listeners) records the
     * tenant it was dispatched under, and runs under exactly that tenant.
     * Jobs dispatched without a tenant run with none. A job whose tenant no
     * longer exists or is suspended fails instead of running.
     */
    private function propagateTenantThroughQueue(): void
    {
        Queue::createPayloadUsing(function () {
            $context = $this->app->make(TenantContext::class);

            return $context->check() ? ['tenant_id' => $context->id()] : [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $context = $this->app->make(TenantContext::class);
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            if ($tenantId === null) {
                $context->forget();

                return;
            }

            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null || ! $tenant->isActive()) {
                $context->forget();

                throw new RuntimeException("Job [{$event->job->resolveName()}] belongs to tenant [{$tenantId}], which is missing or not active.");
            }

            $context->set($tenant);
        });
    }

    /**
     * Migration helpers for tenant-owned tables:
     *
     *   $table->tenantId();                            tenant_id + FK to tenants
     *   $table->tenantScopedKey();                     UNIQUE(tenant_id, id): target for composite FKs
     *   $table->tenantForeignUuid('department_id', 'departments');
     *                                                  FK (tenant_id, department_id) -> departments(tenant_id, id)
     *
     * The composite FK makes cross-tenant references impossible at the
     * database level, independent of application code.
     */
    private function registerSchemaMacros(): void
    {
        Blueprint::macro('tenantId', function (): ColumnDefinition {
            /** @var Blueprint $this */
            $column = $this->foreignUuid('tenant_id');
            $column->constrained('tenants')->restrictOnDelete();

            return $column;
        });

        Blueprint::macro('tenantScopedKey', function (): void {
            /** @var Blueprint $this */
            $this->unique(['tenant_id', 'id']);
        });

        Blueprint::macro('tenantForeignUuid', function (string $column, string $table): ColumnDefinition {
            /** @var Blueprint $this */
            $definition = $this->uuid($column);

            $this->foreign(['tenant_id', $column])
                ->references(['tenant_id', 'id'])
                ->on($table)
                ->restrictOnDelete();

            return $definition;
        });
    }
}
