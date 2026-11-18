<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContextException;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFileStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantStorageAndCacheTest extends TestCase
{
    private Tenant $acme;

    private Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        // In-memory tenants are enough here: storage and cache only need the id.
        $this->acme = (new Tenant)->forceFill(['id' => '0190a000-0000-7000-8000-00000000000a', 'slug' => 'acme']);
        $this->globex = (new Tenant)->forceFill(['id' => '0190a000-0000-7000-8000-00000000000b', 'slug' => 'globex']);
    }

    private function storage(): TenantFileStorage
    {
        return $this->app->make(TenantFileStorage::class);
    }

    private function cache(): TenantCache
    {
        return $this->app->make(TenantCache::class);
    }

    private function as(Tenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantContext::class)->run($tenant, $callback);
    }

    // --- storage -------------------------------------------------------------

    public function test_files_are_stored_under_the_tenants_folder(): void
    {
        $this->as($this->acme, fn () => $this->storage()->put('contracts/a.pdf', 'acme'));

        Storage::assertExists("tenants/{$this->acme->id}/contracts/a.pdf");
    }

    public function test_tenants_cannot_see_each_others_files(): void
    {
        $this->as($this->acme, fn () => $this->storage()->put('a.txt', 'acme data'));

        $this->as($this->globex, function () {
            $this->assertFalse($this->storage()->exists('a.txt'));
            $this->assertNull($this->storage()->get('a.txt'));
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'parent traversal' => ['../0190a000-0000-7000-8000-00000000000b/a.txt'],
            'nested traversal' => ['docs/../../x.txt'],
            'absolute' => ['/etc/passwd'],
            'windows absolute' => ['C:\\Windows\\win.ini'],
            'backslash traversal' => ['..\\x.txt'],
            'dot segment' => ['./a.txt'],
            'empty segment' => ['a//b.txt'],
            'empty' => [''],
            'nul byte' => ["a.txt\0.png"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->as($this->acme, fn () => $this->storage()->put($path, 'x'));
    }

    public function test_storage_without_a_tenant_fails_closed(): void
    {
        $this->expectException(NoTenantContextException::class);

        $this->storage()->put('a.txt', 'x');
    }

    // --- cache ---------------------------------------------------------------

    public function test_cache_entries_are_isolated_per_tenant(): void
    {
        $this->as($this->acme, fn () => $this->cache()->put('settings', 'acme settings'));

        $this->as($this->globex, function () {
            $this->assertFalse($this->cache()->has('settings'));
            $this->assertSame('globex default', $this->cache()->remember('settings', 60, fn () => 'globex default'));
        });

        $this->as($this->acme, fn () => $this->assertSame('acme settings', $this->cache()->get('settings')));
    }

    public function test_cache_without_a_tenant_fails_closed(): void
    {
        $this->expectException(NoTenantContextException::class);

        $this->cache()->get('settings');
    }
}
