<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 80);
            $table->unsignedSmallInteger('days_per_year')->nullable(); // null = no annual limit
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('employee_id', 'employees');
            $table->tenantForeignUuid('leave_type_id', 'leave_types');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days'); // working days, computed server-side
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->tenantForeignUuid('decided_by', 'users')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->tenantScopedKey();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
