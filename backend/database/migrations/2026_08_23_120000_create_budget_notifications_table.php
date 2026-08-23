<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('threshold');
            $table->date('bucket_start');
            $table->timestamp('sent_at')->useCurrent();

            $table->unique(['budget_period_id', 'bucket_start', 'threshold'], 'budget_notifications_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_notifications');
    }
};
