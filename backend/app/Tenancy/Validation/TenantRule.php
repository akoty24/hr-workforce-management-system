<?php

namespace App\Tenancy\Validation;

use App\Tenancy\TenantContext;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Tenant-aware versions of Laravel's database validation rules.
 *
 * The built-in `unique:` / `exists:` rules query the table directly and so
 * bypass the tenant scope. Always use these for tenant-owned tables:
 *
 *   TenantRule::unique('departments', 'name')->ignore($department)
 *   TenantRule::exists('departments')
 */
final class TenantRule
{
    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return (new Unique($table, $column))->where('tenant_id', self::tenantId());
    }

    public static function exists(string $table, string $column = 'id'): Exists
    {
        return (new Exists($table, $column))->where('tenant_id', self::tenantId());
    }

    private static function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }
}
