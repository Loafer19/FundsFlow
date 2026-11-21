<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limits can scope by tags (scope = null), transaction type (kind = type),
 * or summing a numeric field on a type (kind = field_sum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_periods', function (Blueprint $table) {
            $table->json('scope')->nullable()->after('length');
        });
    }

    public function down(): void
    {
        Schema::table('budget_periods', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
