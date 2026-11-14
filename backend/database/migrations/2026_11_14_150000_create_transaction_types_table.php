<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaction types with custom fields ("Fuel": liters, price per liter, a computed total that
 * fills the amount). Values live in transactions.data by stable field key. Existing transactions
 * stay typeless (no backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('emoji', 32)->nullable();
            $table->string('color', 16)->nullable();
            $table->json('fields');
            $table->json('default_tag_ids')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'deleted_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('transaction_type_id')->nullable()->after('note')
                ->constrained('transaction_types')->nullOnDelete();
            $table->json('data')->nullable()->after('transaction_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_type_id');
            $table->dropColumn('data');
        });

        Schema::dropIfExists('transaction_types');
    }
};
