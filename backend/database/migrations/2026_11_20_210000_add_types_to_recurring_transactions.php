<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring rules can carry a type and field template (copied onto each generated transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->foreignId('transaction_type_id')->nullable()->after('note')
                ->constrained('transaction_types')->nullOnDelete();
            $table->json('data')->nullable()->after('transaction_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_type_id');
            $table->dropColumn('data');
        });
    }
};
