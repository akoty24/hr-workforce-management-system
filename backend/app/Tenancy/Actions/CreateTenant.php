<?php

namespace App\Tenancy\Actions;

use App\Models\Tenant;
use App\Tenancy\TenantSlug;
use App\Tenancy\TenantStatus;
use Closure;
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
                'slug' => [
                    'required', 'string', 'regex:'.TenantSlug::PATTERN, 'unique:tenants,slug',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (TenantSlug::isReserved($value)) {
                            $fail('This slug is reserved.');
                        }
                    },
                ],
                'name' => ['required', 'string', 'max:255'],
            ],
            ['slug.regex' => 'The slug must be 3–63 characters: lowercase letters, digits and single hyphens, starting and ending with a letter or digit.'],
        )->validate();

        return Tenant::create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'status' => TenantStatus::Active,
        ]);
    }
}
