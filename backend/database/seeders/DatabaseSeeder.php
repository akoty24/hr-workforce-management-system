<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\Actions\InstallTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data. Refuses to run in production.
 *
 * Note: model events must stay enabled (no WithoutModelEvents) — tenant_id
 * is filled by the BelongsToTenant creating hook.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_ADMIN_EMAIL = 'admin@demo.test';

    public const DEMO_EMPLOYEE_EMAIL = 'employee@demo.test';

    public const DEMO_PASSWORD = 'DemoPassword123';

    public function run(InstallTenant $install, TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must not run in production.');
        }

        ['tenant' => $tenant] = $install->handle(
            'demo', 'Demo Company', 'Demo Admin', self::DEMO_ADMIN_EMAIL, self::DEMO_PASSWORD,
        );

        $context->run($tenant, function () {
            User::create([
                'name' => 'Demo Employee',
                'email' => self::DEMO_EMPLOYEE_EMAIL,
                'password' => self::DEMO_PASSWORD,
                'role' => UserRole::Employee,
            ]);

            foreach ([
                ['Human Resources', 'Recruitment, onboarding and employee relations.'],
                ['Finance', 'Payroll, budgeting and accounting.'],
                ['Engineering', 'Product development and IT.'],
                ['Sales', null],
            ] as [$name, $description]) {
                Department::create(['name' => $name, 'description' => $description]);
            }
        });

        $this->command?->info('Demo tenant "demo" — admin: '.self::DEMO_ADMIN_EMAIL.' / employee: '.self::DEMO_EMPLOYEE_EMAIL.' — password: '.self::DEMO_PASSWORD);
    }
}
