<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->tenantScopedKey();             // target for future composite FKs (employees.department_id)
            $table->unique(['tenant_id', 'name']); // names unique within a company only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
