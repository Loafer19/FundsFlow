<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 120);
            $table->text('body');
            // new | in_progress | closed
            $table->string('status', 16)->default('new');
            $table->string('app_version', 32)->nullable();
            $table->string('user_agent', 512)->nullable();
            // web | telegram
            $table->string('source', 16)->default('web');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            // user | admin
            $table->string('author', 8);
            // The admin who replied (null for the ticket owner's own messages).
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
