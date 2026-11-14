<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activation funnel: per-user timestamps/methods, plus a small events log
 * (widget failures and anything else we don't want to flatten onto users).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_method', 32)->nullable()->after('last_active_at');
            $table->string('signup_source', 255)->nullable()->after('signup_method');
            $table->string('onboarding_step', 32)->nullable()->after('signup_source');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_step');
            $table->timestamp('first_transaction_at')->nullable()->index()->after('onboarding_completed_at');
            $table->timestamp('telegram_linked_at')->nullable()->after('first_transaction_at');
            $table->index(['created_at', 'signup_method']);
        });

        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 64)->index();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'signup_method']);
            $table->dropIndex(['first_transaction_at']);
            $table->dropColumn([
                'signup_method',
                'signup_source',
                'onboarding_step',
                'onboarding_completed_at',
                'first_transaction_at',
                'telegram_linked_at',
            ]);
        });
    }

    private function backfill(): void
    {
        $firstTx = DB::table('transactions')
            ->selectRaw('user_id, MIN(created_at) as first_at')
            ->groupBy('user_id')
            ->get();

        foreach ($firstTx as $row) {
            DB::table('users')->where('id', $row->user_id)->update([
                'first_transaction_at' => $row->first_at,
                // Existing users with a transaction don't need the web onboarding modal.
                'onboarding_completed_at' => $row->first_at,
                'onboarding_step' => 'completed',
            ]);
        }

        $linked = DB::table('identities')
            ->where('provider', 'telegram')
            ->selectRaw('user_id, MIN(created_at) as linked_at')
            ->groupBy('user_id')
            ->get();

        foreach ($linked as $row) {
            DB::table('users')->where('id', $row->user_id)->whereNull('telegram_linked_at')->update([
                'telegram_linked_at' => $row->linked_at,
            ]);
        }

        $identities = DB::table('identities')
            ->whereIn('provider', ['google', 'github'])
            ->get(['user_id', 'provider', 'created_at'])
            ->groupBy('user_id');

        foreach (DB::table('users')->select('id', 'email', 'created_at', 'signup_method')->orderBy('id')->cursor() as $user) {
            if ($user->signup_method) {
                continue;
            }

            $method = 'email';

            if (str_ends_with(strtolower((string) $user->email), '@fundsflow.invalid')) {
                $method = 'telegram';
            } else {
                foreach ($identities->get($user->id, collect()) as $identity) {
                    $created = $user->created_at ? strtotime((string) $user->created_at) : 0;
                    $linkedAt = $identity->created_at ? strtotime((string) $identity->created_at) : 0;

                    if ($created && $linkedAt && abs($linkedAt - $created) <= 120) {
                        $method = (string) $identity->provider;

                        break;
                    }
                }
            }

            DB::table('users')->where('id', $user->id)->update(['signup_method' => $method]);
        }
    }
};
