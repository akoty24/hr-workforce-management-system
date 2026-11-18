<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->target();

        return $target ? $this->user()->can('update', $target) : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->target();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', TenantRule::unique('users', 'email')->ignore($target?->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
            // Required on create; optional on update (admin password reset).
            'password' => [$target ? 'nullable' : 'required', 'string', Password::min(12)->letters()->numbers()],
        ];
    }

    /**
     * Administrators cannot lock themselves out: no self-demotion or
     * self-deactivation. This also guarantees at least one active admin.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $target = $this->target();

                if (! $target || $target->id !== $this->user()->id) {
                    return;
                }

                if ($this->input('role') !== UserRole::Admin->value) {
                    $validator->errors()->add('role', 'You cannot change your own role.');
                }

                if ($this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                }
            },
        ];
    }

    private function target(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
