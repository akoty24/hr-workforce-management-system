<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public holidays: not counted as working days in leave requests,
        // and no hourly permissions on them. One row may span several days.
        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->tenantScopedKey();
            $table->index(['tenant_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
