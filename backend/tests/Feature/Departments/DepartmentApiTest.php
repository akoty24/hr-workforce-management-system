<?php

namespace Tests\Feature\Departments;

use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class DepartmentApiTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private Tenant $globex;

    private User $acmeAdmin;

    private User $acmeEmployee;

    private User $globexAdmin;

    private Department $acmeHr;

    private Department $globexHr;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');

        [$this->acmeAdmin, $this->acmeEmployee, $this->acmeHr] = $this->asTenant($this->acme, fn () => [
            User::factory()->admin()->create(),
            User::factory()->create(),
            Department::create(['name' => 'Human Resources']),
        ]);

        [$this->globexAdmin, $this->globexHr] = $this->asTenant($this->globex, fn () => [
            User::factory()->admin()->create(),
            Department::create(['name' => 'Human Resources', 'description' => 'Globex only']),
        ]);
    }

    private function url(string $tenant, string $path = ''): string
    {
        return "http://{$tenant}.example.test/api/v1/departments{$path}";
    }

    // --- authentication & authorization ----------------------------------

    public function test_guests_cannot_access_departments(): void
    {
        $this->getJson($this->url('acme'))->assertUnauthorized();
        $this->postJson($this->url('acme'), ['name' => 'X'])->assertUnauthorized();
    }

    public function test_employees_can_read_but_not_write(): void
    {
        $this->actingAs($this->acmeEmployee, 'web');

        $this->getJson($this->url('acme'))->assertOk();
        $this->getJson($this->url('acme', "/{$this->acmeHr->id}"))->assertOk();

        $this->postJson($this->url('acme'), ['name' => 'Finance'])->assertForbidden();
        $this->putJson($this->url('acme', "/{$this->acmeHr->id}"), ['name' => 'HR'])->assertForbidden();
        $this->deleteJson($this->url('acme', "/{$this->acmeHr->id}"))->assertForbidden();
    }

    public function test_unauthorized_users_get_403_before_validation(): void
    {
        $this->actingAs($this->acmeEmployee, 'web');

        // Invalid payload, but the employee must not learn anything from validation.
        $this->postJson($this->url('acme'), ['name' => 'Human Resources'])->assertForbidden();
    }

    // --- CRUD ------------------------------------------------------------

    public function test_admin_can_create_list_update_and_delete(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');

        $id = $this->postJson($this->url('acme'), ['name' => '  Finance  ', 'description' => 'Money matters'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Finance')
            ->assertJsonPath('data.description', 'Money matters')
            ->json('data.id');

        $this->getJson($this->url('acme'))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Finance', 'Human Resources']);

        $this->putJson($this->url('acme', "/{$id}"), ['name' => 'Finance & Accounting', 'description' => ''])
            ->assertOk()
            ->assertJsonPath('data.name', 'Finance & Accounting')
            ->assertJsonPath('data.description', null);

        $this->deleteJson($this->url('acme', "/{$id}"))->assertNoContent();
        $this->getJson($this->url('acme', "/{$id}"))->assertNotFound();
    }

    public function test_validation(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');

        $this->postJson($this->url('acme'), [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->postJson($this->url('acme'), ['name' => str_repeat('x', 121)])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->postJson($this->url('acme'), ['name' => 'human resources'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A department with this name already exists.');
    }

    public function test_updating_without_changing_the_name_is_allowed(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');

        $this->putJson($this->url('acme', "/{$this->acmeHr->id}"), ['name' => 'Human Resources', 'description' => 'People'])
            ->assertOk();
    }

    // --- tenant isolation -------------------------------------------------

    public function test_listing_only_shows_the_current_tenants_departments(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');

        $this->getJson($this->url('acme'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->acmeHr->id);
    }

    public function test_another_tenants_department_cannot_be_read_updated_or_deleted(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');
        $foreign = $this->globexHr->id;

        $this->getJson($this->url('acme', "/{$foreign}"))->assertNotFound();
        $this->putJson($this->url('acme', "/{$foreign}"), ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson($this->url('acme', "/{$foreign}"))->assertNotFound();

        $this->assertSame('Human Resources', Department::withoutGlobalScope(TenantScope::class)->find($foreign)->name);
    }

    public function test_an_admin_cannot_use_their_session_on_another_tenant(): void
    {
        $this->actingAs($this->globexAdmin, 'web');

        $this->getJson($this->url('acme'))->assertUnauthorized();
        $this->deleteJson($this->url('acme', "/{$this->acmeHr->id}"))->assertUnauthorized();
    }

    public function test_names_are_unique_per_tenant_only(): void
    {
        // Both tenants already have "Human Resources" (setUp); creating "Finance" in both also works.
        $this->actingAs($this->acmeAdmin, 'web');
        $this->postJson($this->url('acme'), ['name' => 'Finance'])->assertCreated();

        $this->actingAs($this->globexAdmin, 'web');
        $this->postJson($this->url('globex'), ['name' => 'Finance'])->assertCreated();
    }

    public function test_tenant_id_in_the_payload_is_ignored(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');

        $id = $this->postJson($this->url('acme'), ['name' => 'Legal', 'tenant_id' => $this->globex->id])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($this->acme->id, Department::withoutGlobalScope(TenantScope::class)->find($id)->tenant_id);
    }

    public function test_malformed_ids_return_404(): void
    {
        $this->actingAs($this->acmeAdmin, 'web');

        $this->getJson($this->url('acme', '/not-a-uuid'))->assertNotFound();
    }
}
