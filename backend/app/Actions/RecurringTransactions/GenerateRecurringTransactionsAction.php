<?php

namespace App\Actions\RecurringTransactions;

use App\Actions\Transactions\CreateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Enums\TransactionSource;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringTransactionsAction
{
    private const MAX_CATCH_UP = 500;

    /** @var array<int|string, list<string>> */
    private array $messagesByChat = [];

    public function __construct(
        private readonly CreateTransactionAction $createTransaction,
        private readonly TelegramClient $client,
    ) {}

    public function execute(): void
    {
        // Two runs at once (scheduler + manual artisan, or two replicas
        // during a deploy) would materialize the same occurrence twice.
        $lock = Cache::lock('recurring-transactions:generate', 30 * 60);

        if (!$lock->get()) {
            Log::warning('Recurring generation skipped: another run holds the lock.');

            return;
        }

        try {
            $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): void
    {
        $this->messagesByChat = [];

        // Loose UTC bound (max calendar day ahead), then filter by each user's timezone "today".
        $latestPossibleToday = now()->addDay()->toDateString();

        RecurringTransaction::query()
            ->where('active', true)
            ->where('next_run_at', '<=', $latestPossibleToday)
            ->with(['user.identities', 'tags'])
            ->chunkById(50, function ($rules) {
                foreach ($rules as $rule) {
                    $today = $rule->user->todayDateString();

                    if ($rule->next_run_at->toDateString() > $today) {
                        continue;
                    }

                    // One broken rule must not stop generation for everyone else.
                    try {
                        $this->materialize($rule, $today);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        foreach ($this->messagesByChat as $chatId => $lines) {
            try {
                $this->client->sendMessage($chatId, "🔁 Added automatically\n" . implode("\n", $lines));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function materialize(RecurringTransaction $rule, string $today): void
    {
        // A rule can be many occurrences behind if the scheduler was down —
        // catch up in order rather than only firing the latest one.
        $created = 0;


        while ($rule->next_run_at->toDateString() <= $today && $created < self::MAX_CATCH_UP) {
            if ($rule->ends_at && $rule->next_run_at->toDateString() > $rule->ends_at->toDateString()) {
                $rule->update(['active' => false]);

                return;
            }

            // Insert and advance together, so a crash between them can't
            // replay the same occurrence on the next run.
            $transaction = DB::transaction(function () use ($rule) {
                $transaction = $this->createTransaction->execute($rule->user, [
                    'at' => $rule->next_run_at->toDateString(),
                    'amount' => $rule->amount,
                    'note' => $rule->note,
                    'tags' => $rule->tags->pluck('id')->all(),
                ], TransactionSource::Recurring);

                $rule->next_run_at = $rule->frequency->firstOnOrAfter(
                    $rule->starts_at,
                    $rule->next_run_at->copy()->addDay(),
                );
                $rule->save();

                return $transaction;
            });

            $this->queueNotification($rule, $transaction);
            $created++;
        }
    }


    // One combined message per user per run, not one per transaction — a
    // rule catching up several missed days shouldn't spam a message each.
    private function queueNotification(RecurringTransaction $rule, Transaction $transaction): void
    {
        $identity = $rule->user->identities->firstWhere('provider', 'telegram');

        if (!$identity || ($identity->meta['muted'] ?? false)) {
            return;
        }

        $emoji = $transaction->amount > 0 ? '📈' : '📉';
        $note = $transaction->note ? " — {$transaction->note}" : '';
        $user = $rule->user;

        $this->messagesByChat[$identity->external_id][] = sprintf(
            '%s %s%s (%s)',
            $emoji,
            UserFormatter::formatMoney($user, $transaction->amount),
            $note,
            UserFormatter::formatDate($user, $transaction->at),
        );
    }
}
