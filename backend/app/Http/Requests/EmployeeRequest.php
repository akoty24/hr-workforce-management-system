<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        ];
    }

    /**
     * Reporting lines must form a tree: no self-management, no cycles.
     */
    public function after(): array
    {
        return [
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
        ];
    }

    private function employee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
