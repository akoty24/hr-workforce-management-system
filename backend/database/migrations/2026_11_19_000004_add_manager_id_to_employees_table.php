<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Direct manager ("reports to"), within the same tenant (composite FK).
            $table->tenantForeignUuid('manager_id', 'employees')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
