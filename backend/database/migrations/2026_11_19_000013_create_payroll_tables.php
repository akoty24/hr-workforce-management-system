<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unpaid leave types are deducted from the salary in payroll.
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_paid')->default(true)->after('days_per_year');
        });
        DB::table('leave_types')->whereRaw('LOWER(name) LIKE ?', ['%unpaid%'])->update(['is_paid' => false]);

        // One payroll per company per month: a draft that HR reviews, then finalizes (locked).
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('draft');
            $table->tenantForeignUuid('created_by', 'users')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique(['tenant_id', 'year', 'month']);
        });

        // One payslip per employee per payroll, with the full calculation breakdown.
        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('payroll_run_id', 'payroll_runs')->cascadeOnDelete();
            $table->tenantForeignUuid('employee_id', 'employees');
            $table->char('currency', 3);
            $table->decimal('base_salary', 12, 2);
            $table->unsignedSmallInteger('working_days');
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('additions', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('gross', 12, 2);
            $table->decimal('social_insurance', 12, 2)->default(0);
            $table->decimal('income_tax', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->json('items')->nullable(); // manual allowances, bonuses, deductions
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique(['tenant_id', 'payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('is_paid'));
    }
};
