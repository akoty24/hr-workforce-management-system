<?php

namespace App\Tenancy\Actions;

use App\Models\Tenant;
use App\Tenancy\TenantSlug;
use App\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single way tenants are created — used by the on-prem installer, the
 * tenant:create command and (later) SaaS signup.
 */
final class CreateTenant
{
    /**
     * @throws ValidationException
     */
    public function handle(string $slug, string $name): Tenant
    {
        $data = Validator::make(
            ['slug' => strtolower(trim($slug)), 'name' => trim($name)],
            [
                'slug' => TenantSlug::rules(),
                'name' => ['required', 'string', 'max:255'],
            ],
            TenantSlug::messages(),
        )->validate();

        return Tenant::create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'status' => TenantStatus::Active,
        ]);
    }
}
