<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContextException;
use App\Tenancy\TenantContext;
use RuntimeException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    private function tenant(string $id): Tenant
    {
        return (new Tenant)->forceFill(['id' => $id, 'slug' => 'x'.$id]);
    }

    public function test_it_is_empty_by_default_and_fails_closed(): void
    {
        $context = $this->app->make(TenantContext::class);

        $this->assertFalse($context->check());
        $this->assertNull($context->get());

        $this->expectException(NoTenantContextException::class);
        $context->id();
    }

    public function test_it_is_a_scoped_singleton(): void
    {
        $this->assertSame($this->app->make(TenantContext::class), $this->app->make(TenantContext::class));

        $this->app->forgetScopedInstances();

        $this->assertFalse($this->app->make(TenantContext::class)->check());
    }

    public function test_run_restores_the_previous_tenant_even_when_the_callback_throws(): void
    {
        $context = $this->app->make(TenantContext::class);
        $a = $this->tenant('a');
        $b = $this->tenant('b');

        $context->set($a);

        $this->assertSame('b', $context->run($b, fn () => $context->id()));
        $this->assertSame('a', $context->id());

        try {
            $context->run($b, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertSame('a', $context->id());
    }
}
