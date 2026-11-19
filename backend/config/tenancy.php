<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolver
    |--------------------------------------------------------------------------
    |
    | How the current tenant is determined for an HTTP request.
    |
    |  "single"    On-premises installs and single-domain environments
    |              (e.g. *.up.railway.app). Resolves the only tenant, or the
    |              one pinned by TENANCY_SINGLE_TENANT.
    |  "subdomain" SaaS. {slug}.TENANCY_BASE_DOMAIN resolves tenant {slug}.
    |
    */

    'resolver' => env('TENANCY_RESOLVER', 'single'),

    // Subdomain resolver: the domain tenants live under, e.g. "hrworkforce.com"
    // so that "acme.hrworkforce.com" resolves tenant "acme".
    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    // Single resolver: optional slug to pin. When empty, exactly one tenant
    // must exist; otherwise resolution fails closed (404).
    'single_tenant' => env('TENANCY_SINGLE_TENANT'),

    // Owner console (create / suspend companies). Served only on this host;
    // defaults to "admin.{base_domain}" in subdomain mode, disabled otherwise.
    'platform_host' => env('TENANCY_PLATFORM_HOST'),

    // Slugs that can never be tenants (they are, or may become, platform hosts).
    'reserved_slugs' => [
        'admin', 'api', 'app', 'assets', 'auth', 'billing', 'cdn', 'dashboard',
        'docs', 'help', 'login', 'mail', 'platform', 'static', 'status',
        'support', 'www',
    ],

];
