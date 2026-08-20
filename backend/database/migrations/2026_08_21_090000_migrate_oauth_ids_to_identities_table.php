<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')
            ->select('id', 'google_id', 'github_id')
            ->where(function ($query) {
                $query->whereNotNull('google_id')->orWhereNotNull('github_id');
            })
            ->get();

        $now = now();
        $rows = [];

        foreach ($users as $user) {
            foreach (['google', 'github'] as $provider) {
                $externalId = $user->{$provider . '_id'};

                if ($externalId !== null) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'provider' => $provider,
                        'external_id' => $externalId,
                        'meta' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('identities')->insert($rows);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'github_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable();
            $table->string('github_id')->nullable();
        });

        $identities = DB::table('identities')->whereIn('provider', ['google', 'github'])->get();

        foreach ($identities as $identity) {
            DB::table('users')->where('id', $identity->user_id)->update([
                $identity->provider . '_id' => $identity->external_id,
            ]);
        }

        DB::table('identities')->whereIn('provider', ['google', 'github'])->delete();
    }
};
