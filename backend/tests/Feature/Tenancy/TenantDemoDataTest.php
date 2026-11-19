<?php

namespace Tests\Feature\Tenancy;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Salary;
use App\Models\User;
use App\Tenancy\Actions\InstallTenant;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class TenantDemoDataTest extends TestCase
{
    use RefreshTestDatabase;

    public function test_fills_an_existing_company_once_and_keeps_the_admin(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = app(InstallTenant::class)
            ->handle('elnady', 'Elnady', 'Owner', 'owner@elnady.test', 'OwnerPassword123');

        $this->artisan('tenant:demo-data', ['slug' => 'elnady'])
            ->expectsOutputToContain('Demo data added')
            ->assertSuccessful();

        $this->asTenant($tenant, function () use ($admin) {
            $this->assertSame(9, Employee::query()->count());
            $this->assertSame(8, Salary::query()->count());
            $this->assertSame(4, LeaveRequest::query()->count());
            $this->assertTrue(Hash::check('elnadyPassword123', User::query()->where('email', 'hr@demo.test')->value('password')));
            // The company's own admin is untouched.
            $this->assertTrue(Hash::check('OwnerPassword123', $admin->fresh()->password));
        });

        // Running again (e.g. on every deploy) adds nothing.
        $this->artisan('tenant:demo-data')->expectsOutputToContain('already has employees')->assertSuccessful();
        $this->asTenant($tenant, fn () => $this->assertSame(9, Employee::query()->count()));
    }

    public function test_unknown_company_fails(): void
    {
        $this->artisan('tenant:demo-data', ['slug' => 'nope'])->assertFailed();
    }

    private function asTenant($tenant, callable $callback): mixed
    {
        return app(\App\Tenancy\TenantContext::class)->run($tenant, $callback);
    }
}
