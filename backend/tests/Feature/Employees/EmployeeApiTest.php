<?php

namespace Tests\Feature\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private Tenant $globex;

    private User $admin;

    private User $staff;

    private Department $engineering;

    private Department $globexDept;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');

        [$this->admin, $this->staff, $this->engineering] = $this->asTenant($this->acme, fn () => [
            User::factory()->admin()->create(),
            User::factory()->create(),
            Department::create(['name' => 'Engineering']),
        ]);

        $this->globexDept = $this->asTenant($this->globex, fn () => Department::create(['name' => 'Globex Dept']));
    }

    private function url(string $path = '', string $tenant = 'acme'): string
    {
        return "http://{$tenant}.example.test/api/v1/employees{$path}";
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'employee_code' => 'E-1',
            'first_name' => 'Mona',
            'last_name' => 'Hassan',
            'email' => 'mona@acme.test',
            'phone' => '+20 100',
            'job_title' => 'Engineer',
            'hire_date' => '2024-01-15',
            'status' => 'active',
            'department_id' => $this->engineering->id,
            'user_id' => null,
        ];
    }

    public function test_full_crud_with_search_and_filters(): void
    {
        $this->actingAs($this->admin, 'web');

        $id = $this->postJson($this->url(), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Mona Hassan')
            ->assertJsonPath('data.department.name', 'Engineering')
            ->json('data.id');

        $this->postJson($this->url(), $this->payload([
            'employee_code' => 'E-2', 'first_name' => 'Omar', 'email' => null, 'department_id' => null, 'status' => 'terminated',
        ]))->assertCreated();

        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->url('?search=mona'))->assertJsonCount(1, 'data');
        $this->getJson($this->url('?search=E-2'))->assertJsonCount(1, 'data');
        $this->getJson($this->url("?department_id={$this->engineering->id}"))->assertJsonCount(1, 'data');
        $this->getJson($this->url('?status=terminated'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Omar');

        $this->putJson($this->url("/{$id}"), $this->payload(['job_title' => 'Senior Engineer']))
            ->assertOk()->assertJsonPath('data.job_title', 'Senior Engineer');

        $this->deleteJson($this->url("/{$id}"))->assertNoContent();
    }

    public function test_codes_and_emails_are_unique_per_tenant(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->postJson($this->url(), $this->payload())->assertCreated();

        $this->postJson($this->url(), $this->payload(['email' => 'other@acme.test']))
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_code']);
        $this->postJson($this->url(), $this->payload(['employee_code' => 'E-9']))
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_cannot_assign_another_tenants_department(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson($this->url(), $this->payload(['department_id' => $this->globexDept->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['department_id']);
    }

    public function test_a_user_account_links_to_at_most_one_employee(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson($this->url(), $this->payload(['user_id' => $this->staff->id]))->assertCreated();
        $this->postJson($this->url(), $this->payload(['employee_code' => 'E-2', 'email' => null, 'user_id' => $this->staff->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    }

    public function test_non_admins_only_see_their_own_record(): void
    {
        [$own, $other] = $this->asTenant($this->acme, fn () => [
            Employee::create($this->payload(['user_id' => $this->staff->id])),
            Employee::create($this->payload(['employee_code' => 'E-2', 'email' => null])),
        ]);

        $this->actingAs($this->staff, 'web');

        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url("/{$own->id}"))->assertOk();
        $this->getJson($this->url("/{$other->id}"))->assertForbidden();
        $this->postJson($this->url(), $this->payload(['employee_code' => 'E-3']))->assertForbidden();
    }

    public function test_another_tenants_employees_are_invisible(): void
    {
        $foreign = $this->asTenant($this->globex, fn () => Employee::create($this->payload([
            'department_id' => $this->globexDept->id,
        ])));

        $this->actingAs($this->admin, 'web');

        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url("/{$foreign->id}"))->assertNotFound();
        $this->putJson($this->url("/{$foreign->id}"), $this->payload())->assertNotFound();
        $this->deleteJson($this->url("/{$foreign->id}"))->assertNotFound();
    }

    public function test_departments_with_employees_cannot_be_deleted(): void
    {
        $this->asTenant($this->acme, fn () => Employee::create($this->payload()));
        $this->actingAs($this->admin, 'web');

        $this->getJson('http://acme.example.test/api/v1/departments')
            ->assertJsonPath('data.0.employees_count', 1);
        $this->deleteJson("http://acme.example.test/api/v1/departments/{$this->engineering->id}")
            ->assertStatus(409);
    }
}
