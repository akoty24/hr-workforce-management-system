<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $hr;

    private User $staff;

    private User $other;

    private Employee $mona;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);
        Storage::fake();
        $this->acme = $this->createTenant('acme');

        [$this->hr, $this->staff, $this->other, $this->mona] = $this->asTenant($this->acme, function () {
            $staff = User::factory()->create();

            return [
                User::factory()->create(['role' => 'hr']),
                $staff,
                User::factory()->create(),
                Employee::create(['employee_code' => 'E-1', 'first_name' => 'Mona', 'last_name' => 'Hassan', 'status' => 'active', 'user_id' => $staff->id]),
            ];
        });
    }

    private function api(string $path, string $tenant = 'acme'): string
    {
        return "http://{$tenant}.example.test/api/v1{$path}";
    }

    private function upload(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->api("/employees/{$this->mona->id}/documents"), $overrides + [
            'file' => UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf'),
            'type' => 'contract',
            'title' => 'Employment contract',
            'expires_at' => now()->addDays(10)->toDateString(),
        ], ['Accept' => 'application/json']);
    }

    public function test_hr_uploads_lists_downloads_and_deletes(): void
    {
        $this->actingAs($this->hr, 'web');

        $doc = $this->upload()->assertCreated()
            ->assertJsonPath('data.status', 'expiring')
            ->assertJsonPath('data.days_left', 10)
            ->json('data');

        // Stored privately under the tenant's own folder.
        $files = Storage::allFiles("tenants/{$this->acme->id}/documents");
        $this->assertCount(1, $files);

        $this->getJson($this->api("/employees/{$this->mona->id}/documents"))->assertOk()->assertJsonCount(1, 'data');
        $this->get($this->api("/documents/{$doc['id']}/download"))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=contract.pdf');
        $this->getJson($this->api('/documents/expiring'))->assertOk()
            ->assertJsonPath('data.0.employee.full_name', 'Mona Hassan');

        $this->deleteJson($this->api("/documents/{$doc['id']}"))->assertNoContent();
        $this->assertCount(0, Storage::allFiles("tenants/{$this->acme->id}/documents"));
    }

    public function test_deleting_an_employee_removes_their_documents(): void
    {
        $this->actingAs($this->hr, 'web');
        $this->upload()->assertCreated();

        $this->deleteJson($this->api("/employees/{$this->mona->id}"))->assertNoContent();
        $this->assertCount(0, Storage::allFiles("tenants/{$this->acme->id}/documents"));
    }

    public function test_employee_sees_only_own_documents_read_only(): void
    {
        $this->actingAs($this->hr, 'web');
        $id = $this->upload()->json('data.id');

        $this->actingAs($this->staff, 'web');
        $this->getJson($this->api("/employees/{$this->mona->id}/documents"))->assertOk()->assertJsonCount(1, 'data');
        $this->get($this->api("/documents/{$id}/download"))->assertOk();
        $this->upload()->assertForbidden();
        $this->deleteJson($this->api("/documents/{$id}"))->assertForbidden();
        $this->getJson($this->api('/documents/expiring'))->assertForbidden();

        $this->actingAs($this->other, 'web');
        $this->getJson($this->api("/employees/{$this->mona->id}/documents"))->assertForbidden();
        $this->get($this->api("/documents/{$id}/download"), ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_rejects_bad_files_and_other_tenants(): void
    {
        $this->actingAs($this->hr, 'web');

        $this->upload(['file' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(['file' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(['type' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $id = $this->upload()->json('data.id');
        $this->createTenant('globex');
        config(['tenancy.single_tenant' => 'globex']);
        $this->getJson($this->api("/documents/{$id}/download", 'globex'))->assertStatus(401);
    }
}
