<?php

namespace App\Http\Middleware;

use App\Tenancy\Resolvers\TenantResolver;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the request and puts it in TenantContext.
 *
 * Alias: "tenant". Runs before route-model binding (see bootstrap/app.php),
 * so bound tenant-scoped models are already filtered to this tenant.
 *
 *  - no tenant  -> 404 (fail closed; does not reveal which tenants exist)
 *  - suspended  -> 403
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a tenant from an earlier request in the same process.
        $this->context->forget();

        $tenant = $this->resolver->resolve($request);

        abort_if($tenant === null, 404);
        abort_unless($tenant->isActive(), 403, 'This workspace is suspended.');

        $this->context->set($tenant);

        return $next($request);
    }
}
