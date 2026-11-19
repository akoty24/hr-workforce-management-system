<?php

use App\Http\Middleware\AuthenticateTenantUser;
use App\Http\Middleware\EnsurePlatformHost;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Health probes: no "web" middleware group (no session/cookies/CSRF).
            Route::group([], __DIR__.'/../routes/health.php');

            // SPA JSON API: same-origin session auth (web group) for a resolved tenant.
            Route::middleware(['web', 'tenant'])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(__DIR__.'/../routes/api.php');

            // Owner console API: only on the platform host, never with a tenant.
            Route::middleware(['web', 'platform'])
                ->prefix('api/platform')
                ->name('api.platform.')
                ->group(__DIR__.'/../routes/platform.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.auth' => AuthenticateTenantUser::class,
            'platform' => EnsurePlatformHost::class,
        ]);

        // Messages in the SPA's interface language (Accept-Language: ar / en).
        $middleware->web(append: [SetLocaleFromRequest::class]);

        // Order: resolve tenant -> authenticate user of that tenant -> route-model
        // binding. Bound tenant-scoped models are therefore always filtered to the
        // current tenant, and loaded only for an authenticated user.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AuthenticateTenantUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers in JSON (errors included), never HTML or redirects.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
