<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->employee();

        return $employee ? $this->user()->can('update', $employee) : $this->user()->can('create', Employee::class);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['employee_code', 'first_name', 'last_name', 'email', 'phone', 'job_title'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $clean[$field] = $value === '' ? null : $value;
            }
        }

        if (isset($clean['email'])) {
            $clean['email'] = strtolower($clean['email']);
        }

        foreach (['department_id', 'user_id', 'manager_id', 'hire_date'] as $field) {
            if ($this->input($field) === '') {
                $clean[$field] = null;
            }
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->employee()?->id;

        return [
            'employee_code' => ['required', 'string', 'max:30', TenantRule::unique('employees', 'employee_code')->ignore($id)],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'string', 'email', 'max:255', TenantRule::unique('employees', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(EmployeeStatus::class)],
            'department_id' => ['nullable', 'uuid', TenantRule::exists('departments')],
            'user_id' => [
                'nullable', 'uuid', TenantRule::exists('users'),
                TenantRule::unique('employees', 'user_id')->ignore($id),
            ],
            'manager_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            // Optional: create a sign-in account for this employee in the same step.
            'account' => ['sometimes', 'nullable', 'array'],
            'account.role' => ['required_with:account', Rule::in($this->assignableRoles())],
            // Empty = the company's default starting password.
            'account.password' => ['nullable', 'string', Password::min(12)->letters()->numbers()],
            // Optional: reset the password of the linked sign-in account.
            'new_password' => ['sometimes', 'nullable', 'string', Password::min(12)->letters()->numbers()],
        ];
    }

    /**
     * Roles the current user may give when creating a login from the employee form.
     * HR can create everyday logins; only admins can grant HR or admin access.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        return $this->user()->isAdmin()
            ? array_map(fn (UserRole $role) => $role->value, UserRole::cases())
            : [UserRole::Employee->value, UserRole::TeamLeader->value];
    }

    /**
     * @return array{role: string, password: string}|null
     */
    public function account(): ?array
    {
        $account = $this->validated('account');

        return is_array($account) ? $account : null;
    }

    /**
     * Reporting lines must form a tree: no self-management, no cycles.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->filled('new_password')) {
                    return;
                }

                $linked = $this->employee()?->user;

                if (! $linked || $this->input('user_id') !== $linked->id) {
                    $validator->errors()->add('new_password', 'This employee has no sign-in account.');
                } elseif (! in_array($linked->role->value, $this->assignableRoles(), true)) {
                    $validator->errors()->add('new_password', 'Only an admin can reset the password of an HR or admin account.');
                }
            },
            function (Validator $validator) {
                if (! is_array($this->input('account'))) {
                    return;
                }

                if ($this->input('user_id') || $this->employee()?->user_id) {
                    $validator->errors()->add('account', 'This employee already has a sign-in account.');
                } elseif (! $this->input('email')) {
                    $validator->errors()->add('email', 'An email is needed to create a sign-in account.');
                } elseif (! $validator->errors()->has('email')
                    && User::query()->where('email', $this->input('email'))->exists()) {
                    $validator->errors()->add('email', 'A sign-in account already uses this email. Link that account instead.');
                }
            },
            function (Validator $validator) {
                $id = $this->employee()?->id;
                $managerId = $this->input('manager_id');

                if (! $id || ! $managerId || $validator->errors()->has('manager_id')) {
                    return;
                }

                // Walk up from the proposed manager; reaching this employee means a cycle.
                $seen = [];
                for ($current = $managerId; $current !== null && ! isset($seen[$current]); $current = Employee::query()->whereKey($current)->value('manager_id')) {
                    if ($current === $id) {
                        $validator->errors()->add('manager_id', $managerId === $id
                            ? 'An employee cannot report to themselves.'
                            : 'This would create a circular reporting line.');

                        return;
                    }
                    $seen[$current] = true;
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.unique' => 'This employee code is already in use.',
            'email.unique' => 'Another employee already uses this email.',
            'user_id.unique' => 'This user account is already linked to another employee.',
            'department_id.exists' => 'The selected department does not exist.',
            'user_id.exists' => 'The selected user does not exist.',
            'manager_id.exists' => 'The selected manager does not exist.',
            'account.role.in' => 'You cannot give this role.',
            'account.role.required_with' => 'Choose a role.',
        ];
    }

    private function employee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
