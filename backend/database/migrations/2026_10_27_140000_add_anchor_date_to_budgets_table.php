<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Day a limit's weekly/monthly/yearly buckets repeat from (its start date), for
     * all of its periods. Null = calendar weeks/months/years (existing limits, goals).
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->date('anchor_date')->nullable()->after('start_amount');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn('anchor_date');
        });
    }
};
