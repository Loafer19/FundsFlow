<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            // Daily AI assists for this user; null = AI_RECEIPT_DAILY_LIMIT.
            $table->unsignedInteger('ai_daily_limit')->nullable();
            $table->timestamp('blocked_at')->nullable();
            // Filament app (TOTP) MFA for the admin panel.
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'ai_daily_limit', 'blocked_at', 'app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
