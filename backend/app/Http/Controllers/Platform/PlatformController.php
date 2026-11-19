<?php

namespace App\Http\Controllers\Platform;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Tenancy\Actions\InstallTenant;
use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\DefaultPassword;
use App\Tenancy\PlatformHost;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantSlug;
use App\Tenancy\TenantStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Owner console: the system owner creates companies (with their first
 * administrator), renames them and suspends / reactivates them.
 */
class PlatformController
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($data['email']));
        $key = 'platform-login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::guard('platform')->attempt(['email' => $email, 'password' => $data['password']])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return response()->json(['data' => $this->owner(Auth::guard('platform')->user())]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->owner($this->admin($request))]);
    }

    public function password(Request $request): Response
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }

        $admin->update(['password' => $data['password']]);

        return response()->noContent();
    }

    public function tenants(Request $request): JsonResponse
    {
        $this->admin($request);

        // Raw counts: tenant-scoped models refuse to query without a tenant context.
        $users = DB::table('users')->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $employees = DB::table('employees')->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        return response()->json([
            'data' => Tenant::query()->orderBy('name')->get()->map(fn (Tenant $tenant) => $this->tenant($tenant) + [
                'users_count' => (int) ($users[$tenant->id] ?? 0),
                'employees_count' => (int) ($employees[$tenant->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request, InstallTenant $install, TenantContext $context): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['nullable', 'string', Password::min(12)->letters()->numbers()],
        ]);

        // Empty = the company's default "{slug}Password123".
        $default = blank($data['admin_password'] ?? null);
        $password = $default ? strtolower(trim($data['slug'])).'Password123' : $data['admin_password'];

        // Admin fields are already valid here, so remaining errors are the
        // company's own ("slug", "name") and keep their field names.
        ['tenant' => $tenant, 'admin' => $admin] = $install->handle(
            $data['slug'], $data['name'], $data['admin_name'], $data['admin_email'], $password,
        );

        // Someone else chose it: the admin picks their own at first sign-in.
        $context->run($tenant, fn () => $admin->forceFill(['must_change_password' => true])->save());

        return response()->json(['data' => $this->tenant($tenant) + [
            'users_count' => 1,
            'employees_count' => 0,
            'admin_email' => $admin->email,
            'admin_password' => $default ? DefaultPassword::for($tenant) : null,
        ]], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->admin($request);
        if (is_string($request->input('slug'))) {
            $request->merge(['slug' => strtolower(trim($request->input('slug')))]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', ...TenantSlug::rules($tenant->id)],
            'status' => ['sometimes', 'required', Rule::enum(TenantStatus::class)],
        ], TenantSlug::messages());

        DB::transaction(function () use ($tenant, $data) {
            $moved = isset($data['slug']) && $data['slug'] !== $tenant->slug;
            $tenant->update($data);

            if ($moved) {
                // Emailed reset links point at the old address: void them.
                DB::table('password_reset_tokens')->where('tenant_id', $tenant->id)->delete();
            }
        });

        return response()->json(['data' => $this->tenant($tenant)]);
    }

    /** The company's administrators (to see who can sign in, and help them). */
    public function admins(Request $request, Tenant $tenant, TenantContext $context): JsonResponse
    {
        $this->admin($request);

        $admins = $context->run($tenant, fn () => User::query()
            ->where('role', UserRole::Admin->value)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ]));

        return response()->json(['data' => $admins]);
    }

    /** Set a new password for a company administrator who is locked out. */
    public function resetAdminPassword(Request $request, Tenant $tenant, string $user, TenantContext $context): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'password' => ['nullable', 'string', Password::min(12)->letters()->numbers()],
        ]);

        // Empty = back to the company default; they choose their own at next sign-in.
        $default = blank($data['password'] ?? null);
        $password = $default ? DefaultPassword::for($tenant) : $data['password'];

        $context->run($tenant, function () use ($user, $password) {
            $admin = User::query()->whereKey($user)->where('role', UserRole::Admin->value)->firstOrFail();
            $admin->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'must_change_password' => true,
            ])->save();
            DB::table('sessions')->where('user_id', $admin->id)->delete();
        });

        return response()->json(['data' => ['password' => $default ? $password : null]]);
    }

    private function admin(Request $request): PlatformAdmin
    {
        $admin = Auth::guard('platform')->user();
        abort_unless($admin instanceof PlatformAdmin, 401, 'Sign in to the owner console.');

        return $admin;
    }

    /** @return array<string, string> */
    private function owner(PlatformAdmin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email];
    }

    /** @return array<string, mixed> */
    private function tenant(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'status' => $tenant->status->value,
            'url' => PlatformHost::tenantUrl($tenant),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
