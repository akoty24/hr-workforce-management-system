<?php

namespace App\Console\Commands;

use App\Demo\DemoData;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\DefaultPassword;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Fills an existing company with sample data so a trial company can explore
 * the system straight away. Safe to run on every deploy: a company that
 * already has employees is left untouched.
 */
class TenantDemoDataCommand extends Command
{
    protected $signature = 'tenant:demo-data
        {slug? : Company to fill; may be omitted when there is only one}
        {--password= : Password for the demo logins (default: the company\'s {slug}Password123)}';

    protected $description = 'Add sample employees, leave, holidays and salaries to an existing company';

    public function handle(DemoData $demo, TenantContext $context): int
    {
        $slug = $this->argument('slug');
        $tenant = $slug
            ? Tenant::query()->where('slug', $slug)->first()
            : (Tenant::query()->count() === 1 ? Tenant::query()->first() : null);

        if (! $tenant) {
            $this->components->error($slug ? "No company with the address \"{$slug}\"." : 'Name the company: php artisan tenant:demo-data <slug>');

            return self::FAILURE;
        }

        if ($demo->hasData($tenant)) {
            $this->components->info("\"{$tenant->name}\" already has employees; demo data was not added.");

            return self::SUCCESS;
        }

        $admin = $context->run($tenant, fn () => User::query()->where('role', UserRole::Admin->value)->orderBy('created_at')->first());
        if (! $admin) {
            $this->components->error("\"{$tenant->name}\" has no administrator yet. Run tenant:install first.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: DefaultPassword::for($tenant));
        $logins = $demo->fill($tenant, $admin, $password);

        $this->components->info("Demo data added to \"{$tenant->name}\".");
        foreach ($logins as $email) {
            $this->components->twoColumnDetail($email, $password);
        }

        return self::SUCCESS;
    }
}
