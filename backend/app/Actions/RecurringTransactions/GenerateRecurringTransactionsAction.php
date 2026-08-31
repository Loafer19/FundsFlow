<?php

namespace App\Actions\RecurringTransactions;

use App\Actions\Transactions\CreateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Enums\RecurringFrequency;
use App\Enums\TransactionSource;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\UserFormatter;
use Carbon\CarbonInterface;

class GenerateRecurringTransactionsAction
{
    /** @var array<int|string, list<string>> */
    private array $messagesByChat = [];

    public function __construct(
        private readonly CreateTransactionAction $createTransaction,
        private readonly TelegramClient $client,
    ) {}

    public function execute(): void
    {
        $today = now()->toDateString();
        $this->messagesByChat = [];

        RecurringTransaction::query()
            ->where('active', true)
            ->where('next_run_at', '<=', $today)
            ->with(['user.identities', 'tags'])
            ->chunkById(50, function ($rules) use ($today) {
                foreach ($rules as $rule) {
                    $this->materialize($rule, $today);
                }
            });

        foreach ($this->messagesByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "🔁 Added automatically\n" . implode("\n", $lines));
        }
    }

    private function materialize(RecurringTransaction $rule, string $today): void
    {
        // A rule can be many occurrences behind if the scheduler was down —
        // catch up in order rather than only firing the latest one.
        while ($rule->next_run_at->toDateString() <= $today) {
            if ($rule->ends_at && $rule->next_run_at->toDateString() > $rule->ends_at->toDateString()) {
                $rule->update(['active' => false]);

                return;
            }

            $transaction = $this->createTransaction->execute($rule->user, [
                'at' => $rule->next_run_at->toDateString(),
                'amount' => $rule->amount,
                'note' => $rule->note,
                'tags' => $rule->tags->pluck('id')->all(),
            ], TransactionSource::Recurring);

            $this->queueNotification($rule, $transaction);

            $rule->next_run_at = $this->advance($rule->next_run_at, $rule->frequency);
            $rule->save();
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

    private function advance(CarbonInterface $date, RecurringFrequency $frequency): CarbonInterface
    {
        return match ($frequency) {
            RecurringFrequency::Daily => $date->copy()->addDay(),
            RecurringFrequency::Weekly => $date->copy()->addWeek(),
            RecurringFrequency::Monthly => $date->copy()->addMonthsNoOverflow(1),
            RecurringFrequency::Yearly => $date->copy()->addYearsNoOverflow(1),
        };
    }
}
