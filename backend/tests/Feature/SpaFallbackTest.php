<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    private string $index;

    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = public_path('app/index.html');
        $this->original = is_file($this->index) ? file_get_contents($this->index) : null;
    }

    protected function tearDown(): void
    {
        // Leave a developer's real build untouched.
        if ($this->original !== null) {
            file_put_contents($this->index, $this->original);
        } elseif (is_file($this->index)) {
            File::delete($this->index);
        }

        parent::tearDown();
    }

    public function test_page_paths_serve_the_built_spa(): void
    {
        File::ensureDirectoryExists(dirname($this->index));
        file_put_contents($this->index, '<!doctype html><div id="root"></div><!-- spa -->');

        foreach (['/', '/login', '/departments/123'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-cache, private')
                ->assertSee('<!-- spa -->', false);
        }
    }

    public function test_a_helpful_page_is_shown_when_the_spa_is_not_built(): void
    {
        File::delete($this->index);

        $this->get('/')->assertOk()->assertSee('npm run build');
    }

    public function test_unknown_api_paths_return_json_404_not_the_spa(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/nope')->assertNotFound();
    }

    public function test_health_routes_are_not_shadowed(): void
    {
        $this->get('/up')->assertOk();
    }
}
