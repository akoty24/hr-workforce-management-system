<?php

namespace Tests\Feature\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\RequestDecided;
use App\Notifications\RequestSubmitted;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;
use ZipArchive;

class ReportsSalariesNotificationsTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $admin;

    private User $hr;

    private User $leaderUser;

    private User $memberUser;

    private Employee $member;

    private Employee $leader;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        config(['hr.weekend_days' => [5, 6], 'hr.timezone' => 'Africa/Cairo']);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 07:00:00', 'UTC'));
        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->admin = User::factory()->admin()->create();
            $this->hr = User::factory()->create(['role' => UserRole::Hr]);
            $this->leaderUser = User::factory()->create(['role' => UserRole::TeamLeader]);
            $this->memberUser = User::factory()->create();
            $dept = Department::create(['name' => 'Engineering']);
            $this->leader = Employee::create(['employee_code' => 'L1', 'first_name' => 'Ahmed', 'last_name' => 'Ali', 'user_id' => $this->leaderUser->id, 'department_id' => $dept->id]);
            $this->member = Employee::create(['employee_code' => 'M1', 'first_name' => 'منى', 'last_name' => 'حسن', 'user_id' => $this->memberUser->id, 'manager_id' => $this->leader->id, 'department_id' => $dept->id]);
            $this->annual = LeaveType::create(['name' => 'Annual', 'days_per_year' => 21]);
        });
    }

    public function test_salary_history_and_visibility(): void
    {
        $this->actingAs($this->hr, 'web');
        $this->postJson("/api/v1/employees/{$this->member->id}/salaries", ['amount' => 10000, 'effective_from' => '2026-01-01'])
            ->assertCreated()->assertJsonPath('data.currency', 'EGP');
        $this->postJson("/api/v1/employees/{$this->member->id}/salaries", ['amount' => 12500.5, 'effective_from' => '2026-07-01', 'note' => 'Raise'])
            ->assertCreated();
        $future = $this->postJson("/api/v1/employees/{$this->member->id}/salaries", ['amount' => 15000, 'effective_from' => '2027-01-01'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/employees/{$this->member->id}/salaries", ['amount' => -5, 'effective_from' => '2027-01-01'])
            ->assertUnprocessable();

        // Own salary: visible; current = latest effective_from <= today.
        $this->actingAs($this->memberUser, 'web');
        $this->getJson("/api/v1/employees/{$this->member->id}/salaries")
            ->assertOk()
            ->assertJsonPath('data.current.amount', 12500.5)
            ->assertJsonCount(3, 'data.history');
        $this->postJson("/api/v1/employees/{$this->member->id}/salaries", ['amount' => 1, 'effective_from' => '2026-12-01'])->assertForbidden();

        // Team leaders do not see their team's salaries.
        $this->actingAs($this->leaderUser, 'web');
        $this->getJson("/api/v1/employees/{$this->member->id}/salaries")->assertForbidden();

        $this->actingAs($this->hr, 'web');
        $this->deleteJson("/api/v1/salaries/{$future}")->assertNoContent();
    }

    public function test_reports_preview_and_excel_export(): void
    {
        $this->actingAs($this->memberUser, 'web');
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => 30.1, 'longitude' => 31.2])->assertCreated();
        $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $this->annual->id, 'start_date' => '2026-12-06', 'end_date' => '2026-12-07'])->assertCreated();
        $this->getJson('/api/v1/reports/summary?from=2026-12-01&to=2026-12-31')->assertForbidden();

        $this->actingAs($this->hr, 'web');
        $range = 'from=2026-12-01&to=2026-12-31';

        $this->getJson("/api/v1/reports/attendance?{$range}")->assertOk()
            ->assertJsonPath('data.rows.0.1', 'منى حسن')
            ->assertJsonPath('data.rows.0.8', 'https://www.google.com/maps?q=30.1,31.2');
        $this->getJson("/api/v1/reports/leave?{$range}")->assertOk()->assertJsonCount(1, 'data.rows');
        $this->getJson("/api/v1/reports/summary?{$range}")->assertOk()->assertJsonCount(2, 'data.rows');
        $this->getJson("/api/v1/reports/salaries?{$range}")->assertOk();
        $this->getJson("/api/v1/reports/permissions?{$range}")->assertOk();
        $this->getJson("/api/v1/reports/payroll?{$range}")->assertNotFound();
        $this->getJson('/api/v1/reports/leave?from=2026-12-31&to=2026-12-01')->assertUnprocessable();

        $response = $this->get("/api/v1/reports/attendance?{$range}&format=xlsx")->assertOk();
        $this->assertStringContainsString('attendance-2026-12-01-to-2026-12-31.xlsx', (string) $response->headers->get('content-disposition'));

        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('منى حسن', $sheet);
        $this->assertStringContainsString('Check in', $sheet);
    }

    public function test_emails_go_to_the_team_leader_then_back_to_the_employee(): void
    {
        Notification::fake();

        $this->actingAs($this->memberUser, 'web');
        $id = $this->postJson('/api/v1/leave-requests', ['leave_type_id' => $this->annual->id, 'start_date' => '2026-12-06', 'end_date' => '2026-12-07'])
            ->assertCreated()->json('data.id');

        Notification::assertSentTo($this->leaderUser, RequestSubmitted::class);
        Notification::assertNotSentTo([$this->admin, $this->hr, $this->memberUser], RequestSubmitted::class);

        $this->actingAs($this->leaderUser, 'web');
        $this->postJson("/api/v1/leave-requests/{$id}/approve", ['note' => 'Enjoy'])->assertOk();

        Notification::assertSentTo($this->memberUser, RequestDecided::class, function (RequestDecided $n) {
            $mail = $n->toMail($this->memberUser);

            return str_contains($mail->subject, 'approved') && in_array('Note: Enjoy', $mail->introLines, true);
        });
    }

    public function test_without_a_team_leader_admins_and_hr_are_emailed(): void
    {
        Notification::fake();

        // The team leader requests a permission: nobody above them, so admins + HR decide.
        $this->actingAs($this->leaderUser, 'web');
        $id = $this->postJson('/api/v1/short-leaves', ['date' => '2026-12-03', 'start_time' => '09:00', 'end_time' => '10:00'])
            ->assertCreated()->json('data.id');

        Notification::assertSentTo([$this->admin, $this->hr], RequestSubmitted::class);
        Notification::assertNotSentTo($this->leaderUser, RequestSubmitted::class);

        // Employee record without an account: the decision goes to the record's e-mail.
        $this->asTenant($this->acme, fn () => $this->leader->update(['user_id' => null, 'email' => 'ahmed@example.com']));
        $this->actingAs($this->hr, 'web');
        $this->postJson("/api/v1/short-leaves/{$id}/reject")->assertOk();

        Notification::assertSentTo(new AnonymousNotifiable, RequestDecided::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'ahmed@example.com');
    }
}
