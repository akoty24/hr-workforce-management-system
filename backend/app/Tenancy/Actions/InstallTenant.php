<?php

namespace App\Tenancy\Actions;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Creates a tenant together with its first administrator, atomically.
 * Used by `tenant:install` (on-prem, dev, Railway) and the local demo seeder.
 */
final class InstallTenant
{
    public function __construct(
        private readonly CreateTenant $createTenant,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{tenant: Tenant, admin: User}
     *
     * @throws ValidationException
     */
    public function handle(string $slug, string $name, string $adminName, string $adminEmail, string $adminPassword): array
    {
        $admin = Validator::make(
            ['name' => trim($adminName), 'email' => strtolower(trim($adminEmail)), 'password' => $adminPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            ],
        )->validate();

        return DB::transaction(function () use ($slug, $name, $admin) {
            $tenant = $this->createTenant->handle($slug, $name);

            $user = $this->context->run($tenant, fn () => User::create([
                'name' => $admin['name'],
                'email' => $admin['email'],
                'password' => $admin['password'],
                'role' => UserRole::Admin,
            ]));

            return ['tenant' => $tenant, 'admin' => $user];
        });
    }
}
