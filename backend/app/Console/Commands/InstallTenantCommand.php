<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\Actions\InstallTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstallTenantCommand extends Command
{
    protected $signature = 'tenant:install
        {--slug= : DNS-safe tenant identifier, e.g. "acme"}
        {--name= : Company display name}
        {--admin-name= : First administrator\'s name}
        {--admin-email= : First administrator\'s email}
        {--admin-password= : Leave empty to generate a strong password}';

    protected $description = 'Create a tenant together with its first administrator';

    public function handle(InstallTenant $install): int
    {
        if (config('tenancy.resolver') === 'single' && blank(config('tenancy.single_tenant')) && Tenant::query()->exists()) {
            $this->components->error('A tenant already exists. With TENANCY_RESOLVER=single only one tenant can be served unless TENANCY_SINGLE_TENANT is set.');

            return self::FAILURE;
        }

        $slug = $this->option('slug') ?: $this->ask('Tenant slug (e.g. acme)');
        $name = $this->option('name') ?: $this->ask('Company name');
        $adminName = $this->option('admin-name') ?: $this->ask('Administrator name');
        $adminEmail = $this->option('admin-email') ?: $this->ask('Administrator email');
        $password = $this->option('admin-password');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(20, symbols: false);
        }

        try {
            ['tenant' => $tenant, 'admin' => $admin] = $install->handle(
                (string) $slug, (string) $name, (string) $adminName, (string) $adminEmail, (string) $password,
            );
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('Tenant "%s" installed.', $tenant->name));
        $this->components->twoColumnDetail('Tenant slug', $tenant->slug);
        $this->components->twoColumnDetail('Admin email', $admin->email);

        if ($generated) {
            $this->components->twoColumnDetail('Admin password', $password);
            $this->components->warn('Store this password now; it is not shown again.');
        }

        return self::SUCCESS;
    }
}
