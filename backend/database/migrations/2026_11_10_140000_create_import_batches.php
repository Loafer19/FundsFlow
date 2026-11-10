<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('source', 32)->default('web');
            $table->string('status', 24)->default('previewing');
            $table->string('filename')->nullable();
            $table->string('format', 32)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('header_signature', 64)->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->json('category_map')->nullable();
            $table->json('created_tag_ids')->nullable();
            $table->json('draft')->nullable();
            $table->json('notes')->nullable();
            $table->boolean('ai_used')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('transfer_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('needs_tag_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->decimal('income_total', 15, 2)->default(0);
            $table->decimal('expense_total', 15, 2)->default(0);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->string('status', 24)->default('new');
            $table->date('at')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('note')->nullable();
            $table->string('category')->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('raw')->nullable();
            $table->json('tag_ids')->nullable();
            $table->json('suggested_tag_ids')->nullable();
            $table->json('new_tag_titles')->nullable();
            $table->boolean('manual')->default(false);
            $table->string('skip_reason')->nullable();
            $table->string('error')->nullable();
            $table->unsignedBigInteger('matched_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
            $table->index(['import_batch_id', 'line']);
        });

        Schema::create('import_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('header_signature', 64);
            $table->json('mapping');
            $table->json('category_map')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'header_signature']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('external_meta')
                ->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
        Schema::dropIfExists('import_templates');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
