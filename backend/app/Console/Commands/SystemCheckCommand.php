<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pre-flight check for any deployment (on-prem, Railway, cloud): verifies
 * configuration, database connectivity, schema state and writable paths.
 * Exit code 0 = ready; 1 = at least one blocking problem.
 */
class SystemCheckCommand extends Command
{
    protected $signature = 'system:check';

    protected $description = 'Verify configuration, database and storage before serving traffic';

    private bool $failed = false;

    public function handle(Migrator $migrator): int
    {
        // The command instance is reused by Artisan within a process.
        $this->failed = false;

        $this->check('Application key', filled(config('app.key')), 'APP_KEY is empty. Run: php artisan key:generate --show');

        if (app()->isProduction()) {
            $this->check('Debug mode off', ! config('app.debug'), 'APP_DEBUG must be false in production.');
        }

        $this->check('Database driver is mysql', config('database.default') === 'mysql', 'DB_CONNECTION must be "mysql".');

        try {
            $version = DB::selectOne('select version() as v')->v;
            $this->check("Database reachable ({$version})", true);

            if (str_contains(strtolower($version), 'mariadb') && app()->isProduction()) {
                $this->components->warn('MariaDB detected. Supported production database is MySQL 8.4 LTS.');
            }

            $migrator->setConnection(config('database.default'));
            $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $ran);
            $this->check('Migrations up to date', $pending === [], count($pending).' pending migration(s). Run: php artisan migrate --force');

            if ($pending === []) {
                $tenants = Tenant::query()->count();
                $this->check("Tenants configured ({$tenants})", $tenants > 0, 'No tenant yet. Run: php artisan tenant:install');
            }
        } catch (Throwable $e) {
            $this->check('Database reachable', false, $e->getMessage());
        }

        $resolver = config('tenancy.resolver');
        $this->check(
            "Tenant resolver ({$resolver})",
            $resolver === 'single' || ($resolver === 'subdomain' && filled(config('tenancy.base_domain'))),
            'TENANCY_RESOLVER must be "single", or "subdomain" with TENANCY_BASE_DOMAIN set.',
        );

        foreach ([storage_path('app'), storage_path('framework/cache'), storage_path('framework/views'), base_path('bootstrap/cache')] as $path) {
            $this->check('Writable: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), is_dir($path) && is_writable($path), "{$path} must exist and be writable.");
        }

        $this->newLine();

        if ($this->failed) {
            $this->components->error('System check failed.');

            return self::FAILURE;
        }

        $this->components->info('System check passed.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, string $hint = ''): void
    {
        $this->components->twoColumnDetail($label, $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>');

        if (! $ok) {
            $this->failed = true;
            $this->line("  <fg=gray>→ {$hint}</>");
        }
    }
}
