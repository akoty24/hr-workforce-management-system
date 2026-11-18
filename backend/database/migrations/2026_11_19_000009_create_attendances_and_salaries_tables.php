<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per employee per (company-local) day. Times stored in UTC.
        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('employee_id', 'employees');
            $table->date('date');
            $table->dateTime('check_in_at')->nullable();
            $table->decimal('check_in_latitude', 9, 6)->nullable();
            $table->decimal('check_in_longitude', 9, 6)->nullable();
            $table->unsignedInteger('check_in_accuracy')->nullable(); // metres
            $table->dateTime('check_out_at')->nullable();
            $table->decimal('check_out_latitude', 9, 6)->nullable();
            $table->decimal('check_out_longitude', 9, 6)->nullable();
            $table->unsignedInteger('check_out_accuracy')->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->nullable();
            $table->string('note', 500)->nullable();
            $table->tenantForeignUuid('edited_by', 'users')->nullable(); // set when HR corrects a record
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique(['tenant_id', 'employee_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });

        // Salary history: the current salary is the latest effective_from <= today.
        Schema::create('salaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('employee_id', 'employees');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EGP');
            $table->date('effective_from');
            $table->string('note', 500)->nullable();
            $table->tenantForeignUuid('created_by', 'users')->nullable();
            $table->timestamps();

            $table->tenantScopedKey();
            $table->index(['tenant_id', 'employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salaries');
        Schema::dropIfExists('attendances');
    }
};
