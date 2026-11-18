<?php

namespace Tests\Feature\Tenancy;

use App\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\Fixtures\Jobs\RecordTenantJob;
use Tests\TestCase;

class TenantQueueTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordTenantJob::$seen = [];
        config(['queue.default' => 'database']);
    }

    private function workOneJob(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    }

    public function test_jobs_run_under_the_tenant_they_were_dispatched_with_and_never_leak(): void
    {
        $acme = $this->createTenant('acme');
        $globex = $this->createTenant('globex');

        $this->asTenant($acme, fn () => RecordTenantJob::dispatch());
        RecordTenantJob::dispatch(); // dispatched with no tenant
        $this->asTenant($globex, fn () => RecordTenantJob::dispatch());

        $this->assertFalse($this->tenantContext()->check(), 'Dispatching must not change the caller context.');

        $this->workOneJob();
        $this->workOneJob();
        $this->workOneJob();

        $this->assertSame([$acme->id, null, $globex->id], RecordTenantJob::$seen);
    }

    public function test_dispatch_inside_a_statement_closure_also_carries_the_tenant(): void
    {
        $acme = $this->createTenant('acme');

        $this->asTenant($acme, function () {
            RecordTenantJob::dispatch();
        });

        $this->workOneJob();

        $this->assertSame([$acme->id], RecordTenantJob::$seen);
    }

    public function test_the_payload_records_the_tenant(): void
    {
        $acme = $this->createTenant('acme');

        $this->asTenant($acme, fn () => RecordTenantJob::dispatch());

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame($acme->id, $payload['tenant_id']);
    }

    public function test_jobs_of_a_suspended_tenant_fail_instead_of_running(): void
    {
        $acme = $this->createTenant('acme');
        $this->asTenant($acme, fn () => RecordTenantJob::dispatch());

        $acme->update(['status' => TenantStatus::Suspended]);

        $this->workOneJob();

        $this->assertSame([], RecordTenantJob::$seen);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_sync_jobs_keep_the_callers_tenant(): void
    {
        config(['queue.default' => 'sync']);
        $acme = $this->createTenant('acme');

        $this->asTenant($acme, function () use ($acme) {
            RecordTenantJob::dispatch();

            $this->assertSame($acme->id, $this->tenantContext()->id());
        });

        $this->assertSame([$acme->id], RecordTenantJob::$seen);
    }
}
