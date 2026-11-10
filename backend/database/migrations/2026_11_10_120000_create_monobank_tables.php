<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monobank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->uuid('webhook_uuid')->unique();
            $table->string('status', 16)->default('active');
            $table->boolean('notify')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('monobank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monobank_connection_id')->constrained('monobank_connections')->cascadeOnDelete();
            $table->string('mono_account_id');
            $table->unsignedSmallInteger('currency_code');
            $table->string('type', 32)->default('black');
            $table->string('masked_pan')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('title')->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->timestamps();

            $table->unique(['monobank_connection_id', 'mono_account_id'], 'monobank_accounts_connection_mono_unique');
        });

        Schema::create('monobank_tag_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_key', 190);
            $table->unsignedSmallInteger('mcc')->nullable();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'merchant_key'], 'monobank_tag_rules_user_merchant_unique');
            $table->index(['user_id', 'mcc']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('external_source')->nullable()->after('source');
            $table->string('external_id')->nullable()->after('external_source');
            $table->json('external_meta')->nullable()->after('external_id');
            $table->unique(['user_id', 'external_source', 'external_id'], 'transactions_user_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_user_external_unique');
            $table->dropColumn(['external_source', 'external_id', 'external_meta']);
        });

        Schema::dropIfExists('monobank_tag_rules');
        Schema::dropIfExists('monobank_accounts');
        Schema::dropIfExists('monobank_connections');
    }
};
