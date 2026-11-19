<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Validation\Rule;

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

    /**
     * Validation rules for a new or changed slug.
     *
     * @return list<mixed>
     */
    public static function rules(?string $ignoreTenantId = null): array
    {
        return [
            'required', 'string', 'regex:'.self::PATTERN,
            Rule::unique('tenants', 'slug')->ignore($ignoreTenantId),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && self::isReserved($value)) {
                    $fail('This address is reserved.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'slug.regex' => 'The address must be 3–63 characters: lowercase letters, digits and single hyphens, starting and ending with a letter or digit.',
            'slug.unique' => 'Another company already uses this address.',
        ];
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, config('tenancy.reserved_slugs', []), true);
    }
}
