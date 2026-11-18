<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('department_id', 'departments')->nullable();
            $table->tenantForeignUuid('user_id', 'users')->nullable(); // optional sign-in account
            $table->string('employee_code', 30);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique(['tenant_id', 'employee_code']);
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
