<?php

namespace App\Tenancy;

use App\Models\Tenant;

/** Where the owner console lives, and each company's own web address. */
final class PlatformHost
{
    /** Host of the owner console, or null when it is disabled. */
    public static function host(): ?string
    {
        $configured = config('tenancy.platform_host');

        if (filled($configured)) {
            return strtolower((string) $configured);
        }

        if (config('tenancy.resolver') === 'subdomain' && filled(config('tenancy.base_domain'))) {
            return 'admin.'.strtolower(trim((string) config('tenancy.base_domain'), '.'));
        }

        return null;
    }

    /** Address of a company's app (scheme and port follow APP_URL). */
    public static function tenantUrl(Tenant $tenant): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if (config('tenancy.resolver') !== 'subdomain' || blank(config('tenancy.base_domain'))) {
            return $base;
        }

        $parts = parse_url($base);

        return ($parts['scheme'] ?? 'https').'://'.$tenant->slug.'.'.trim((string) config('tenancy.base_domain'), '.')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
