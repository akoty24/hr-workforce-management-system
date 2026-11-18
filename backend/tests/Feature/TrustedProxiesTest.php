<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test/scheme', fn (Request $request) => $request->getScheme());
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function bootWithTrustedProxies(?string $value): void
    {
        config(['app.trusted_proxies' => $value]);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_forwarded_headers_are_ignored_when_no_proxies_are_trusted(): void
    {
        $this->bootWithTrustedProxies(null);

        $this->get('/__test/scheme', ['X-Forwarded-Proto' => 'https'])->assertContent('http');
    }

    public function test_forwarded_proto_is_honoured_when_all_proxies_are_trusted(): void
    {
        $this->bootWithTrustedProxies('*');

        $this->get('/__test/scheme', ['X-Forwarded-Proto' => 'https'])->assertContent('https');
    }

    public function test_forwarded_proto_is_honoured_for_a_listed_proxy(): void
    {
        // The test client connects from 127.0.0.1.
        $this->bootWithTrustedProxies(' 10.0.0.0/8 , 127.0.0.1 ');

        $this->get('/__test/scheme', ['X-Forwarded-Proto' => 'https'])->assertContent('https');
    }
}
