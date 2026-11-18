<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_liveness_endpoint_returns_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_readiness_returns_ok_when_database_is_reachable(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonStructure(['status', 'checks' => ['database' => ['status', 'latency_ms']]]);
    }

    public function test_readiness_returns_503_without_leaking_details_when_mysql_is_unreachable(): void
    {
        Log::spy();

        // Point the app at a MySQL server that cannot exist (closed port).
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
            'database.connections.mysql.username' => 'secret_user_name',
            'database.connections.mysql.options' => [\PDO::ATTR_TIMEOUT => 1],
        ]);
        DB::purge('mysql');

        $response = $this->getJson('/health/ready');

        $response
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'checks' => ['database' => ['status' => 'fail']],
            ]);

        // Internal details stay in the logs, never in the public response.
        $this->assertStringNotContainsString('secret_user_name', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_health_endpoints_do_not_start_a_session(): void
    {
        $this->get('/up')->assertCookieMissing(config('session.cookie'));
        $this->getJson('/health/ready')->assertCookieMissing(config('session.cookie'));
    }
}
