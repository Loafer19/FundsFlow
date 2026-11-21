<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My cards" on Analytics: the user's own stat / chart / pie cards. The config JSON is
 * normalized by App\Support\DashboardCardConfig (web API and assistant alike).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->json('config');
            $table->string('size', 1)->default('M');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_cards');
    }
};
