<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();
    }

    /**
     * Trust proxies from configuration (TRUSTED_PROXIES) instead of hardcoding
     * a hosting provider, so the same build works behind any TLS terminator.
     */
    private function configureTrustedProxies(): void
    {
        $proxies = trim((string) config('app.trusted_proxies', ''));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }
}
