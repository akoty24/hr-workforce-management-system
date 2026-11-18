<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Session (cookie) authentication for the same-origin SPA.
 * All routes run inside the "web" + "tenant" middleware groups, so user
 * lookups are confined to the current tenant.
 */
class AuthController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    public function login(Request $request): UserResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $throttleKey = 'login:'.$this->context->id().'|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ])->status(429);
        }

        $authenticated = Auth::guard('web')->attemptWhen(
            ['email' => $email, 'password' => $credentials['password']],
            fn (User $user) => $user->is_active,
        );

        if (! $authenticated) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records, or the account is disabled.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate(); // prevent session fixation

        return new UserResource($request->user()->load('employee'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('employee'));
    }

    /**
     * Public: which workspace is this? Also issues the XSRF-TOKEN cookie the
     * SPA needs before its first state-changing request.
     */
    public function tenant(): JsonResponse
    {
        $tenant = $this->context->current();

        return response()->json(['data' => ['name' => $tenant->name, 'slug' => $tenant->slug]]);
    }
}
