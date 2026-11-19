<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files kept on an employee's record (contract, national ID, ...).
        // The file itself lives in the tenant's private storage folder.
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('employee_id', 'employees')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title', 120);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->date('expires_at')->nullable();
            $table->tenantForeignUuid('uploaded_by', 'users')->nullable();
            $table->timestamps();

            $table->tenantScopedKey();
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
