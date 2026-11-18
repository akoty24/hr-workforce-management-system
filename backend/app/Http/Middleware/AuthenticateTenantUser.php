<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a signed-in user who belongs to the current tenant.
 *
 * Alias: "tenant.auth". Must run after "tenant". The session guard already
 * loads users through the tenant scope, so a session from another tenant
 * resolves to nobody; this middleware additionally asserts the binding and
 * ends any session whose user does not match the tenant (defence in depth).
 */
class AuthenticateTenantUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user === null) {
            return $this->unauthenticated();
        }

        // Wrong tenant, or the account was deactivated after signing in.
        if ($user->getAttribute('tenant_id') !== $this->context->id() || ! $user->getAttribute('is_active')) {
            // Drop the session without writing to the foreign user's record
            // (logout() would rotate its remember token, i.e. a cross-tenant write).
            $guard->forgetUser();
            $request->session()->invalidate();

            return $this->unauthenticated();
        }

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
