<?php

namespace App\Http\Controllers\Api;

use App\Tenancy\DefaultPassword;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** The signed-in user's own account. */
class AccountController
{
    public function __construct(private readonly TenantContext $context) {}

    public function updatePassword(Request $request): Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [
                'required', 'string', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers(),
                // The company's well-known starting password is never a real password.
                Rule::notIn([DefaultPassword::for($this->context->current())]),
            ],
        ], ['password.not_in' => 'Choose your own password, not the company\'s starting password.']);

        $user = $request->user();
        $user->update(['password' => $validated['password'], 'must_change_password' => false]);

        // Keep this session (new id), end the user's sessions on other devices.
        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return response()->noContent();
    }
}
