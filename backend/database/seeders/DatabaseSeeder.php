<?php

namespace Database\Seeders;

use App\Leave\LeaveService;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Holiday;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserRole;
use App\Tenancy\Actions\InstallTenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data. Refuses to run in production.
 *
 * Note: model events must stay enabled (no WithoutModelEvents) — tenant_id
 * is filled by the BelongsToTenant creating hook.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_ADMIN_EMAIL = 'admin@demo.test';

    public const DEMO_EMPLOYEE_EMAIL = 'employee@demo.test';

    public const DEMO_LEADER_EMAIL = 'leader@demo.test';

    public const DEMO_HR_EMAIL = 'hr@demo.test';

    public const DEMO_PASSWORD = 'DemoPassword123';

    public function run(InstallTenant $install, TenantContext $context, LeaveService $leave): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must not run in production.');
        }

        ['tenant' => $tenant, 'admin' => $admin] = $install->handle(
            'demo', 'Demo Company', 'Demo Admin', self::DEMO_ADMIN_EMAIL, self::DEMO_PASSWORD,
        );

        $context->run($tenant, function () use ($admin, $leave) {
            $employeeUser = User::create([
                'name' => 'Mona Hassan',
                'email' => self::DEMO_EMPLOYEE_EMAIL,
                'password' => self::DEMO_PASSWORD,
                'role' => UserRole::Employee,
            ]);

            $leaderUser = User::create([
                'name' => 'Ahmed Ali',
                'email' => self::DEMO_LEADER_EMAIL,
                'password' => self::DEMO_PASSWORD,
                'role' => UserRole::TeamLeader,
            ]);

            $hrUser = User::create([
                'name' => 'Sara Mahmoud',
                'email' => self::DEMO_HR_EMAIL,
                'password' => self::DEMO_PASSWORD,
                'role' => UserRole::Hr,
            ]);

            // Public holidays (dates relative to today so they show as upcoming).
            $base = now()->startOfDay();
            Holiday::create(['name' => 'National Day', 'start_date' => $base->copy()->addDays(20)->toDateString(), 'end_date' => $base->copy()->addDays(20)->toDateString()]);
            Holiday::create(['name' => 'New Year Holiday', 'start_date' => $base->copy()->addYear()->startOfYear()->toDateString(), 'end_date' => $base->copy()->addYear()->startOfYear()->toDateString()]);

            $departments = [];
            foreach ([
                ['Human Resources', 'Recruitment, onboarding and employee relations.'],
                ['Finance', 'Payroll, budgeting and accounting.'],
                ['Engineering', 'Product development and IT.'],
                ['Sales', null],
            ] as [$name, $description]) {
                $departments[$name] = Department::create(['name' => $name, 'description' => $description]);
            }

            $people = [
                ['EMP-001', 'Mona', 'Hassan', 'Engineering', 'Software Engineer', '2022-03-01', EmployeeStatus::Active, $employeeUser->id],
                ['EMP-002', 'Ahmed', 'Ali', 'Engineering', 'Engineering Manager', '2019-07-15', EmployeeStatus::Active, $leaderUser->id],
                ['EMP-003', 'Sara', 'Mahmoud', 'Human Resources', 'HR Specialist', '2021-01-10', EmployeeStatus::Active, $hrUser->id],
                ['EMP-004', 'Omar', 'Khaled', 'Finance', 'Accountant', '2020-09-20', EmployeeStatus::Active, null],
                ['EMP-005', 'Nour', 'Ibrahim', 'Sales', 'Account Executive', '2023-05-02', EmployeeStatus::Active, null],
                ['EMP-006', 'Youssef', 'Adel', 'Sales', 'Sales Manager', '2018-11-11', EmployeeStatus::Active, null],
                ['EMP-007', 'Laila', 'Samir', 'Finance', 'Financial Analyst', '2024-02-18', EmployeeStatus::OnLeave, null],
                ['EMP-008', 'Karim', 'Fathy', 'Engineering', 'QA Engineer', '2017-04-03', EmployeeStatus::Terminated, null],
                // Joined mid-year: annual leave is prorated (6 of 12 months).
                ['EMP-009', 'Salma', 'Mostafa', 'Engineering', 'Junior Developer', now()->startOfYear()->addMonths(6)->addDays(11)->toDateString(), EmployeeStatus::Active, null],
            ];

            $employees = [];
            foreach ($people as [$code, $first, $last, $dept, $title, $hired, $status, $userId]) {
                $employees[$code] = Employee::create([
                    'employee_code' => $code,
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => strtolower("{$first}.{$last}@demo.test"),
                    'phone' => '+20 10 '.random_int(1000, 9999).' '.random_int(1000, 9999),
                    'job_title' => $title,
                    'department_id' => $departments[$dept]->id,
                    'hire_date' => $hired,
                    'status' => $status,
                    'user_id' => $userId,
                ]);
            }

            // Reporting lines: Ahmed (team leader) manages Mona, Karim and Salma; Youssef manages Nour.
            foreach (['EMP-001' => 'EMP-002', 'EMP-008' => 'EMP-002', 'EMP-009' => 'EMP-002', 'EMP-005' => 'EMP-006'] as $code => $managerCode) {
                $employees[$code]->update(['manager_id' => $employees[$managerCode]->id]);
            }

            $annual = LeaveType::create(['name' => 'Annual Leave', 'days_per_year' => 21, 'prorate_first_year' => true]);
            $sick = LeaveType::create(['name' => 'Sick Leave', 'days_per_year' => 15]);
            LeaveType::create(['name' => 'Unpaid Leave', 'days_per_year' => null]);

            // A few requests around today so the dashboard has content.
            $today = CarbonImmutable::today();
            $nextWeek = $today->next(CarbonImmutable::SUNDAY);

            $approved = $leave->submit($employees['EMP-002'], $annual, $today->subDay(), $today->addDays(2), 'Family trip');
            $leave->decide($approved, $admin, LeaveStatus::Approved, 'Enjoy!');

            $leave->submit($employees['EMP-001'], $annual, $nextWeek->addWeek(), $nextWeek->addWeek()->addDays(3), 'Vacation');
            $leave->submit($employees['EMP-004'], $sick, $nextWeek, $nextWeek->addDay(), 'Medical appointment');

            $rejected = $leave->submit($employees['EMP-005'], $annual, $nextWeek->addWeeks(3), $nextWeek->addWeeks(3)->addDays(4), 'Conference');
            $leave->decide($rejected, $admin, LeaveStatus::Rejected, 'End-of-quarter sales push; please pick another week.');
        });

        $this->command?->info('Demo tenant "demo" — admin: '.self::DEMO_ADMIN_EMAIL.' / HR: '.self::DEMO_HR_EMAIL.' / team leader: '.self::DEMO_LEADER_EMAIL.' / employee: '.self::DEMO_EMPLOYEE_EMAIL.' — password: '.self::DEMO_PASSWORD);
    }
}
