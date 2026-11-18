<?php

namespace App\Tenancy\Resolvers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * On-premises / single-domain: every request belongs to the one installed
 * tenant.
 *
 * With a pinned slug, that tenant is used. Without one, exactly one tenant
 * must exist; zero or several resolve to null (fail closed) rather than
 * guessing.
 */
final class SingleTenantResolver implements TenantResolver
{
    public function __construct(private readonly ?string $pinnedSlug = null) {}

    public function resolve(Request $request): ?Tenant
    {
        if (filled($this->pinnedSlug)) {
            return Tenant::query()->where('slug', $this->pinnedSlug)->first();
        }

        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() > 1) {
            Log::warning('Single-tenant resolver found several tenants; set TENANCY_SINGLE_TENANT to choose one.');

            return null;
        }

        return $tenants->first();
    }
}
