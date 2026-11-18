<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Tenancy\Validation\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class DepartmentRequest extends FormRequest
{
    /**
     * Authorize before validating, so unauthorized users get 403 rather than
     * learning anything from validation errors (e.g. which names exist).
     */
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            ? $this->user()->can('update', $department)
            : $this->user()->can('create', Department::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'description' => is_string($this->description) && trim($this->description) !== '' ? trim($this->description) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'name' => [
                'required', 'string', 'max:120',
                TenantRule::unique('departments', 'name')->ignore($department instanceof Department ? $department->id : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A department with this name already exists.',
        ];
    }
}
