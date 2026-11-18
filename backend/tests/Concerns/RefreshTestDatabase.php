<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * RefreshDatabase with two additions:
 *  - a hard guard: refuses to wipe any database whose name does not end in
 *    "_testing", so tests can never destroy development or production data;
 *  - test-only fixture tables (tests/Fixtures/migrations) for tenancy tests.
 */
trait RefreshTestDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to refresh database [{$database}]: test databases must end in \"_testing\".");
        }

        $this->app['migrator']->path(base_path('tests/Fixtures/migrations'));
    }
}
