<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin dashboard: a persistent "last seen" stamp that also covers Telegram-only users
 * (web / Mini App / MCP activity already lands in personal_access_tokens.last_used_at),
 * plus indexes for the per-day signup / transaction / activity aggregates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index();
            $table->index('created_at');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['last_used_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['last_active_at']);
            $table->dropColumn('last_active_at');
        });
    }
};
