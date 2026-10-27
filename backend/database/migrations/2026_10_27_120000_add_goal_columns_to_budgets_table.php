<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Savings goals live in the budgets table (type = 'goal'); every existing row
 * stays a spending limit. Purely additive: one defaulted and two nullable
 * columns, no rewrite of budget_periods. A goal keeps its target amount and
 * source tags on its BudgetPeriod row (the length column there is a stored
 * placeholder that goals ignore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->string('type', 16)->default('limit')->after('title');
            // Optional deadline of a goal.
            $table->date('target_date')->nullable()->after('type');
            // Already saved before tracking started; counts toward the goal.
            $table->decimal('start_amount', 10, 2)->nullable()->after('target_date');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['type', 'target_date', 'start_amount']);
        });
    }
};
