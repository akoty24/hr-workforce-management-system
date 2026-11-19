<?php

namespace App\Http\Controllers\Api;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Tenancy\PlatformHost;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password" for signed-out users, scoped to the current tenant.
 * The request endpoint never reveals whether an account exists.
 */
class PasswordResetController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 600;

    public function __construct(private readonly TenantContext $context) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $email = Str::lower(trim($data['email']));

        $this->throttle('forgot', $request, $email);

        $user = User::query()->where('email', $email)->where('is_active', true)->first();

        if ($user) {
            $token = Str::random(64);

            DB::transaction(function () use ($user, $token, $email) {
                PasswordResetToken::query()->where('user_id', $user->id)->delete();
                PasswordResetToken::create([
                    'user_id' => $user->id,
                    'token_hash' => PasswordResetToken::hash($token),
                    'expires_at' => now()->addMinutes(PasswordResetToken::LIFETIME_MINUTES),
                ]);
                $user->notify(new ResetPasswordLink($this->link($token, $email)));
            });
        }

        return response()->json([
            'message' => 'If an account uses this email, we sent it a link to reset the password. Check your inbox.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        $email = Str::lower(trim($data['email']));

        $this->throttle('reset', $request, $email);

        $record = PasswordResetToken::query()
            ->with('user')
            ->where('token_hash', PasswordResetToken::hash($data['token']))
            ->first();

        if (! $record || $record->expires_at->isPast() || ! $record->user
            || ! $record->user->is_active || $record->user->email !== $email) {
            throw ValidationException::withMessages([
                'token' => 'This reset link is invalid or has expired. Ask for a new one.',
            ]);
        }

        $user = $record->user;

        DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
                // They chose it themselves through the emailed link.
                'must_change_password' => false,
            ])->save();
            PasswordResetToken::query()->where('user_id', $user->id)->delete();
            // Sign the account out everywhere: whoever knew the old password loses access.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        RateLimiter::clear($this->key('reset', $request, $email));

        return response()->json(['message' => 'Your password was changed. You can sign in now.']);
    }

    private function throttle(string $action, Request $request, string $email): void
    {
        $key = $this->key($action, $request, $email);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.(int) ceil(RateLimiter::availableIn($key) / 60).' minutes.',
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
    }

    private function key(string $action, Request $request, string $email): string
    {
        return "password-{$action}:".$this->context->id().'|'.$email.'|'.$request->ip();
    }

    /** Link to this company's app; subdomain installs get the tenant's own host. */
    private function link(string $token, string $email): string
    {
        return PlatformHost::tenantUrl($this->context->current()).'/reset-password?'.http_build_query(['token' => $token, 'email' => $email]);
    }
}
