<?php

namespace App\Tenancy\Resolvers;

use App\Models\Tenant;
use App\Tenancy\TenantSlug;
use Illuminate\Http\Request;

/**
 * SaaS: "{slug}.{base domain}" resolves tenant {slug}.
 *
 * Only a single label directly under the base domain is accepted. The bare
 * base domain, nested subdomains, reserved slugs and foreign hosts never
 * resolve to a tenant.
 */
final class SubdomainTenantResolver implements TenantResolver
{
    public function __construct(private readonly string $baseDomain) {}

    public function resolve(Request $request): ?Tenant
    {
        $host = strtolower($request->getHost());
        $suffix = '.'.strtolower(trim($this->baseDomain, '.'));

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if (str_contains($slug, '.') || ! TenantSlug::isValid($slug)) {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }
}
