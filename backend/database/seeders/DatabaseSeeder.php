<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Tenancy\Actions\InstallTenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data. Refuses to run in production (there, fill a real company
 * with `php artisan tenant:demo-data`).
 *
 * Note: model events must stay enabled (no WithoutModelEvents) — tenant_id
 * is filled by the BelongsToTenant creating hook.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_ADMIN_EMAIL = 'admin@demo.test';

    public const DEMO_EMPLOYEE_EMAIL = DemoData::EMPLOYEE_EMAIL;

    public const DEMO_LEADER_EMAIL = DemoData::LEADER_EMAIL;

    public const DEMO_HR_EMAIL = DemoData::HR_EMAIL;

    public const DEMO_PASSWORD = 'DemoPassword123';

    /** Demo monthly salaries in EGP (the terminated employee has none). */
    public const DEMO_SALARIES = DemoData::SALARIES;

    public function run(InstallTenant $install, DemoData $demo): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must not run in production.');
        }

        ['tenant' => $tenant, 'admin' => $admin] = $install->handle(
            'demo', 'Demo Company', 'Demo Admin', self::DEMO_ADMIN_EMAIL, self::DEMO_PASSWORD,
        );

        $demo->fill($tenant, $admin, self::DEMO_PASSWORD);

        $this->command?->info('Demo tenant "demo" — admin: '.self::DEMO_ADMIN_EMAIL.' / HR: '.self::DEMO_HR_EMAIL.' / team leader: '.self::DEMO_LEADER_EMAIL.' / employee: '.self::DEMO_EMPLOYEE_EMAIL.' — password: '.self::DEMO_PASSWORD);
    }
}
