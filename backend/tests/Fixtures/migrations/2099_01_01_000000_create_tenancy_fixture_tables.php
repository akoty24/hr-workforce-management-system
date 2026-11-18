<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only tables exercising the tenancy schema helpers exactly as real
 * modules will use them. Loaded only by tests (RefreshTestDatabase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name');
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('fixture_gadgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('widget_id', 'fixture_widgets');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_gadgets');
        Schema::dropIfExists('fixture_widgets');
    }
};
