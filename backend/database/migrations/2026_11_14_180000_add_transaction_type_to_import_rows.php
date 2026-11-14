<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            // The type a row gets on import; type_source: suggested | rule | manual.
            $table->unsignedBigInteger('transaction_type_id')->nullable()->after('tag_ids');
            $table->string('type_source', 16)->nullable()->after('transaction_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropColumn(['transaction_type_id', 'type_source']);
        });
    }
};
