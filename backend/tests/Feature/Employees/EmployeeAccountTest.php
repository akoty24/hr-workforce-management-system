<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

/** Creating a sign-in account straight from the employee form. */
class EmployeeAccountTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    private User $hr;

    private const PASSWORD = 'Welcome-2026-pass';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);

        $this->acme = $this->createTenant('acme');

        [$this->admin, $this->hr] = $this->asTenant($this->acme, fn () => [
            User::factory()->admin()->create(),
            User::factory()->create(['role' => UserRole::Hr]),
        ]);
    }

    private function url(string $path = ''): string
    {
        return "http://acme.example.test/api/v1/employees{$path}";
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'employee_code' => 'E-1',
            'first_name' => 'Mona',
            'last_name' => 'Hassan',
            'email' => 'Mona@Acme.test',
            'status' => 'active',
        ];
    }

    public function test_creating_an_employee_with_an_account_links_a_working_login(): void
    {
        $this->actingAs($this->admin, 'web');

        $response = $this->postJson($this->url(), $this->payload([
            'account' => ['role' => 'employee', 'password' => self::PASSWORD],
        ]))->assertCreated()->assertJsonPath('data.user.email', 'mona@acme.test');

        $this->asTenant($this->acme, function () use ($response) {
            $user = User::query()->where('email', 'mona@acme.test')->firstOrFail();
            $this->assertSame('Mona Hassan', $user->name);
            $this->assertSame(UserRole::Employee, $user->role);
            $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
            $this->assertSame($user->id, Employee::query()->find($response->json('data.id'))->user_id);
        });
    }

    public function test_an_existing_employee_can_get_a_login_later(): void
    {
        $this->actingAs($this->admin, 'web');
        $id = $this->postJson($this->url(), $this->payload())->assertCreated()->json('data.id');

        $this->putJson($this->url("/{$id}"), $this->payload([
            'account' => ['role' => 'team_leader', 'password' => self::PASSWORD],
        ]))->assertOk()->assertJsonPath('data.user.email', 'mona@acme.test');

        // A second account cannot be created for the same employee.
        $this->putJson($this->url("/{$id}"), $this->payload([
            'account' => ['role' => 'employee', 'password' => self::PASSWORD],
        ]))->assertUnprocessable()->assertJsonValidationErrors('account');
    }

    public function test_account_needs_an_unused_email_and_a_strong_password(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->postJson($this->url(), $this->payload([
            'email' => null, 'account' => ['role' => 'employee', 'password' => self::PASSWORD],
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson($this->url(), $this->payload([
            'email' => $this->hr->email, 'account' => ['role' => 'employee', 'password' => self::PASSWORD],
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson($this->url(), $this->payload([
            'account' => ['role' => 'employee', 'password' => 'short'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('account.password');

        // Nothing was half-created.
        $this->asTenant($this->acme, fn () => $this->assertSame(0, Employee::query()->count()));
    }

    public function test_hr_can_create_everyday_logins_but_not_grant_admin_or_hr(): void
    {
        $this->actingAs($this->hr, 'web');

        foreach (['admin', 'hr'] as $i => $role) {
            $this->postJson($this->url(), $this->payload([
                'employee_code' => "E-{$i}", 'email' => "x{$i}@acme.test",
                'account' => ['role' => $role, 'password' => self::PASSWORD],
            ]))->assertUnprocessable()->assertJsonValidationErrors('account.role');
        }

        $this->postJson($this->url(), $this->payload([
            'account' => ['role' => 'team_leader', 'password' => self::PASSWORD],
        ]))->assertCreated()->assertJsonPath('data.user.email', 'mona@acme.test');
    }

    public function test_the_linked_login_password_can_be_reset_from_the_employee_form(): void
    {
        $this->actingAs($this->admin, 'web');
        $created = $this->postJson($this->url(), $this->payload([
            'account' => ['role' => 'employee', 'password' => self::PASSWORD],
        ]))->assertCreated();
        $id = $created->json('data.id');
        $userId = $created->json('data.user.id');

        $this->putJson($this->url("/{$id}"), $this->payload(['user_id' => $userId, 'new_password' => 'Brand-new-2026-pass']))
            ->assertOk();
        $this->asTenant($this->acme, fn () => $this->assertTrue(
            Hash::check('Brand-new-2026-pass', User::query()->findOrFail($userId)->password),
        ));

        // HR may reset an employee login, but never an admin's.
        $this->actingAs($this->hr, 'web');
        $this->putJson($this->url("/{$id}"), $this->payload(['user_id' => $userId, 'new_password' => 'Another-2026-pass']))
            ->assertOk();

        $adminEmployee = $this->asTenant($this->acme, fn () => Employee::create([
            'employee_code' => 'A-1', 'first_name' => 'Ad', 'last_name' => 'Min', 'status' => 'active', 'user_id' => $this->admin->id,
        ]));
        $this->putJson($this->url("/{$adminEmployee->id}"), [
            'employee_code' => 'A-1', 'first_name' => 'Ad', 'last_name' => 'Min', 'status' => 'active',
            'user_id' => $this->admin->id, 'new_password' => 'Hijack-2026-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');
    }
}
