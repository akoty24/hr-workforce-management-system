<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // When true, an employee hired during a year gets a share of the
            // yearly allowance proportional to the months left in that year.
            $table->boolean('prorate_first_year')->default(false)->after('days_per_year');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('prorate_first_year');
        });
    }
};
