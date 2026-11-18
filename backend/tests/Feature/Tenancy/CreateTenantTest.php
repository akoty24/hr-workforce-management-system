<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Actions\CreateTenant;
use App\Tenancy\TenantStatus;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use RefreshTestDatabase;

    private function create(string $slug, string $name = 'Acme Ltd'): Tenant
    {
        return $this->app->make(CreateTenant::class)->handle($slug, $name);
    }

    public function test_it_creates_an_active_tenant_with_a_uuid_v7_id(): void
    {
        $tenant = $this->create('  Acme-HR ', ' Acme Ltd ');

        $this->assertSame('acme-hr', $tenant->slug);
        $this->assertSame('Acme Ltd', $tenant->name);
        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $tenant->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSlugs(): array
    {
        return [
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 64)],
            'leading hyphen' => ['-acme'],
            'trailing hyphen' => ['acme-'],
            'double hyphen' => ['ac--me'],
            'underscore' => ['ac_me'],
            'dot' => ['ac.me'],
            'non-ascii' => ['شركة'],
            'reserved' => ['www'],
            'reserved admin' => ['admin'],
        ];
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slugs_are_rejected(string $slug): void
    {
        $this->expectException(ValidationException::class);

        $this->create($slug);
    }

    public function test_duplicate_slugs_are_rejected(): void
    {
        $this->create('acme');

        $this->expectException(ValidationException::class);

        $this->create('ACME');
    }

    public function test_command_creates_a_tenant(): void
    {
        $this->artisan('tenant:create', ['slug' => 'globex', 'name' => 'Globex Corp'])
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['slug' => 'globex', 'name' => 'Globex Corp', 'status' => 'active']);
    }

    public function test_command_reports_validation_errors(): void
    {
        $this->artisan('tenant:create', ['slug' => 'www', 'name' => 'Nope'])
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
    }
}
