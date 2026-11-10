<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monobank_accounts', function (Blueprint $table) {
            $table->decimal('balance', 15, 2)->nullable()->after('title');
            $table->decimal('goal_amount', 15, 2)->nullable()->after('balance');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->string('monobank_jar_id')->nullable()->after('anchor_date');
            $table->decimal('monobank_jar_balance', 15, 2)->nullable()->after('monobank_jar_id');
            $table->decimal('monobank_jar_balance_at_link', 15, 2)->nullable()->after('monobank_jar_balance');
            $table->timestamp('monobank_jar_unlinked_at')->nullable()->after('monobank_jar_balance_at_link');
            $table->index(['user_id', 'monobank_jar_id']);
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'monobank_jar_id']);
            $table->dropColumn([
                'monobank_jar_id',
                'monobank_jar_balance',
                'monobank_jar_balance_at_link',
                'monobank_jar_unlinked_at',
            ]);
        });

        Schema::table('monobank_accounts', function (Blueprint $table) {
            $table->dropColumn(['balance', 'goal_amount']);
        });
    }
};
