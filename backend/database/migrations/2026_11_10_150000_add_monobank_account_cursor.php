<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monobank_accounts', function (Blueprint $table) {
            // Unix time of the newest statement item already fetched for this card.
            $table->unsignedInteger('last_item_time')->nullable()->after('tag_id');
        });

        // Existing connections: start from their last sync so deleted items stay deleted.
        foreach (\Illuminate\Support\Facades\DB::table('monobank_connections')->whereNotNull('last_synced_at')->get(['id', 'last_synced_at']) as $connection) {
            \Illuminate\Support\Facades\DB::table('monobank_accounts')
                ->where('monobank_connection_id', $connection->id)
                ->whereNull('last_item_time')
                ->update(['last_item_time' => \Illuminate\Support\Carbon::parse($connection->last_synced_at)->timestamp]);
        }
    }

    public function down(): void
    {
        Schema::table('monobank_accounts', function (Blueprint $table) {
            $table->dropColumn('last_item_time');
        });
    }
};
