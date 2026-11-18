<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private User $hr;

    private User $leaderUser;

    private User $memberUser;

    private User $outsiderUser;

    private Employee $member;

    private const HERE = ['latitude' => 30.0444, 'longitude' => 31.2357, 'accuracy' => 12.4];

    protected function setUp(): void
    {
        parent::setUp();

        config(['hr.weekend_days' => [5, 6], 'hr.timezone' => 'Africa/Cairo']);
        // Wednesday 2 Dec 2026, 09:20 in Cairo (UTC+2) = 07:20 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-12-02 07:20:00', 'UTC'));

        $this->acme = $this->createTenant('acme');

        $this->asTenant($this->acme, function () {
            $this->hr = User::factory()->create(['role' => UserRole::Hr]);
            $this->leaderUser = User::factory()->create(['role' => UserRole::TeamLeader]);
            $this->memberUser = User::factory()->create();
            $this->outsiderUser = User::factory()->create();
            $leader = Employee::create(['employee_code' => 'L1', 'first_name' => 'Ahmed', 'last_name' => 'Ali', 'user_id' => $this->leaderUser->id]);
            $this->member = Employee::create(['employee_code' => 'M1', 'first_name' => 'Mona', 'last_name' => 'H', 'user_id' => $this->memberUser->id, 'manager_id' => $leader->id]);
            Employee::create(['employee_code' => 'O1', 'first_name' => 'Omar', 'last_name' => 'K', 'user_id' => $this->outsiderUser->id]);
        });
    }

    public function test_check_in_and_out_record_time_location_and_lateness(): void
    {
        $this->actingAs($this->memberUser, 'web');

        $this->getJson('/api/v1/attendance/today')->assertOk()
            ->assertJsonPath('data.record', null)
            ->assertJsonPath('data.schedule.work_start', '09:00');

        // 20 minutes after 09:00 is beyond the 15-minute grace => 20 minutes late.
        $this->postJson('/api/v1/attendance/check-in', self::HERE)
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-12-02')
            ->assertJsonPath('data.check_in', '09:20')
            ->assertJsonPath('data.late_minutes', 20)
            ->assertJsonPath('data.check_in_location.latitude', 30.0444)
            ->assertJsonPath('data.check_in_location.accuracy', 12);

        $this->postJson('/api/v1/attendance/check-in', self::HERE)
            ->assertUnprocessable()->assertJsonValidationErrors('attendance');

        $this->travel(8)->hours();
        $this->postJson('/api/v1/attendance/check-out', ['latitude' => 30.05, 'longitude' => 31.24])
            ->assertOk()
            ->assertJsonPath('data.check_out', '17:20')
            ->assertJsonPath('data.worked_minutes', 480)
            ->assertJsonPath('data.check_out_location.longitude', 31.24);

        $this->postJson('/api/v1/attendance/check-out', self::HERE)
            ->assertUnprocessable()->assertJsonValidationErrors('attendance');
    }

    public function test_within_grace_weekend_and_holiday_are_not_late(): void
    {
        $this->actingAs($this->memberUser, 'web');

        $this->travelTo(CarbonImmutable::parse('2026-12-02 07:10:00', 'UTC')); // 09:10 Cairo, within grace
        $this->postJson('/api/v1/attendance/check-in', self::HERE)->assertJsonPath('data.late_minutes', 0);

        $this->travelTo(CarbonImmutable::parse('2026-12-04 09:00:00', 'UTC')); // Friday 11:00 Cairo
        $this->postJson('/api/v1/attendance/check-in', self::HERE)->assertJsonPath('data.late_minutes', 0);

        $this->asTenant($this->acme, fn () => Holiday::create(['name' => 'Day', 'start_date' => '2026-12-06', 'end_date' => '2026-12-06']));
        $this->travelTo(CarbonImmutable::parse('2026-12-06 09:00:00', 'UTC'));
        $this->postJson('/api/v1/attendance/check-in', self::HERE)->assertJsonPath('data.late_minutes', 0);
    }

    public function test_location_is_required_unless_disabled(): void
    {
        $this->actingAs($this->memberUser, 'web');

        $this->postJson('/api/v1/attendance/check-in', [])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => 120, 'longitude' => 31])
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');

        $this->actingAs($this->hr, 'web');
        $this->putJson('/api/v1/settings', [
            'timezone' => 'Africa/Cairo', 'work_start' => '09:00', 'work_end' => '17:00', 'grace_minutes' => 15, 'require_location' => false,
        ])->assertOk();

        $this->actingAs($this->memberUser, 'web');
        $this->postJson('/api/v1/attendance/check-in', [])->assertCreated()->assertJsonPath('data.check_in_location', null);
    }

    public function test_company_day_follows_the_company_time_zone(): void
    {
        // 23:30 UTC on 1 Dec is 01:30 on 2 Dec in Cairo.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 23:30:00', 'UTC'));
        $this->actingAs($this->memberUser, 'web');

        $this->postJson('/api/v1/attendance/check-in', self::HERE)->assertJsonPath('data.date', '2026-12-02');
    }

    public function test_visibility_and_hr_corrections(): void
    {
        $this->actingAs($this->memberUser, 'web');
        $id = $this->postJson('/api/v1/attendance/check-in', self::HERE)->json('data.id');

        $this->getJson('/api/v1/attendance')->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/attendance/{$id}", ['check_in' => '09:00'])->assertForbidden();

        $this->actingAs($this->outsiderUser, 'web');
        $this->getJson('/api/v1/attendance')->assertJsonCount(0, 'data');

        $this->actingAs($this->leaderUser, 'web');
        $this->getJson('/api/v1/attendance?scope=team')->assertJsonCount(1, 'data');

        $this->actingAs($this->hr, 'web');
        $this->putJson("/api/v1/attendance/{$id}", ['check_in' => '08:55', 'check_out' => '17:05', 'note' => 'Card reader issue'])
            ->assertOk()
            ->assertJsonPath('data.check_in', '08:55')
            ->assertJsonPath('data.late_minutes', 0)
            ->assertJsonPath('data.worked_minutes', 490)
            ->assertJsonPath('data.edited_by', $this->hr->name);

        $this->putJson("/api/v1/attendance/{$id}", ['check_in' => '10:00', 'check_out' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('check_out');

        // A forgotten day, added by HR; a second record for the same day is refused.
        $this->postJson('/api/v1/attendance', ['employee_id' => $this->member->id, 'date' => '2026-12-01', 'check_in' => '09:30', 'check_out' => '17:00'])
            ->assertCreated()->assertJsonPath('data.late_minutes', 30);
        $this->postJson('/api/v1/attendance', ['employee_id' => $this->member->id, 'date' => '2026-12-01', 'check_in' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_settings_are_validated_and_hr_only(): void
    {
        $valid = ['timezone' => 'Europe/London', 'work_start' => '08:00', 'work_end' => '16:00', 'grace_minutes' => 10, 'require_location' => true];

        $this->actingAs($this->memberUser, 'web');
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.timezone', 'Africa/Cairo');
        $this->putJson('/api/v1/settings', $valid)->assertForbidden();

        $this->actingAs($this->hr, 'web');
        $this->putJson('/api/v1/settings', ['timezone' => 'Mars/Base'] + $valid)->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->putJson('/api/v1/settings', ['work_end' => '07:00'] + $valid)->assertUnprocessable()->assertJsonValidationErrors('work_end');
        $this->putJson('/api/v1/settings', $valid)->assertOk()->assertJsonPath('data.work_start', '08:00');

        // Settings belong to one company.
        $globex = $this->createTenant('globex');
        $this->asTenant($globex, fn () => $this->assertSame('09:00', app(CompanySettings::class)->all()['work_start']));
    }
}
