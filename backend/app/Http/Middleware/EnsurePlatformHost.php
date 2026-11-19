<?php

namespace App\Http\Middleware;

use App\Tenancy\PlatformHost;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias "platform": the owner console API exists only on the platform host
 * (404 everywhere else, so company sites do not even reveal it).
 * Never runs with a tenant in context.
 */
class EnsurePlatformHost
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = PlatformHost::host();

        abort_if($host === null || strtolower($request->getHost()) !== $host, 404);

        // Controllers use Auth::guard('platform') explicitly; the default guard is
        // left alone so it never leaks into company requests in long-lived workers.
        $this->context->forget();

        return $next($request);
    }
}
