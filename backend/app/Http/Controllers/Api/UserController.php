<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Company administrators manage sign-in accounts. Accounts are deactivated,
 * never deleted, so history (e.g. leave decisions) stays attributable.
 */
class UserController
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        return UserResource::collection(
            User::query()->with('employee')->orderBy('name')->paginate(100),
        );
    }

    public function store(UserRequest $request): UserResource
    {
        // The admin chose this password, so the user replaces it at first sign-in.
        $user = User::create($request->safe()->only(['name', 'email', 'role', 'password', 'is_active']) + [
            'must_change_password' => true,
        ]);

        return new UserResource($user->load('employee'));
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $data = $request->safe()->only(['name', 'email', 'role', 'is_active']);

        if ($request->filled('password')) {
            $data['password'] = $request->validated('password');
            // Resetting someone else's password: they must choose their own next time.
            $data['must_change_password'] = $user->id !== $request->user()->id;
        }

        $user->update($data);

        return new UserResource($user->load('employee'));
    }
}
