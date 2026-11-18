<?php

namespace App\Tenancy;

/**
 * Tenant slug rules. A slug is a DNS label (it becomes the SaaS subdomain):
 * 3–63 chars, lowercase a–z, 0–9 and single hyphens, starting and ending with
 * a letter or digit, and not reserved.
 */
final class TenantSlug
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9]|-(?!-)){1,61}[a-z0-9]$/';

    public static function isValid(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1
            && ! self::isReserved($slug);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, config('tenancy.reserved_slugs', []), true);
    }
}
