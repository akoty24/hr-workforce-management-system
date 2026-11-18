<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hourly permissions: part of one working day, approved like leave,
        // not deducted from any leave balance.
        Schema::create('short_leaves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('employee_id', 'employees');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('minutes');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->tenantForeignUuid('decided_by', 'users')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->tenantScopedKey();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_leaves');
    }
};
