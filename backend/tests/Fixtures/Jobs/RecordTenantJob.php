<?php

namespace Tests\Fixtures\Jobs;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Records which tenant (if any) was in context while the job ran. */
class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string|null> */
    public static array $seen = [];

    public function handle(TenantContext $context): void
    {
        static::$seen[] = $context->get()?->getKey();
    }
}
