<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-aware "forgot password" tokens. Only a SHA-256 hash of the
        // token is stored; the plain token exists only in the emailed link.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->tenantForeignUuid('user_id', 'users');
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->tenantScopedKey();
            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
