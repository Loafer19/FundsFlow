<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_period_tag', function (Blueprint $table) {
            $table->foreignId('budget_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['budget_period_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_period_tag');
    }
};
