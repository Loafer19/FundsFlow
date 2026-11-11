<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Reply to "Edit amount" on a saved transaction card: a bare number keeps the direction.
 */
class EditAmountHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly UpdateTransactionAction $updateTransaction,
    ) {}

    public function handleEditAmountReply(User $user, int|string $chatId, string $text): void
    {
        $cacheKey = "telegram_edit_amount:{$chatId}";
        $transactionId = (int) Cache::get($cacheKey);

        if (!preg_match('/^([+-]?\d+(?:[.,]\d{1,2})?)$/u', trim($text), $matches)) {
            $this->client->sendMessage($chatId, 'Send the new amount like +120 or -50.5');

            return;
        }

        $amount = (float) str_replace(',', '.', $matches[1]);
        $hasExplicitSign = str_starts_with($matches[1], '+') || str_starts_with($matches[1], '-');

        if ($amount === 0.0) {
            $this->client->sendMessage($chatId, "Amount can't be zero");

            return;
        }

        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            Cache::forget($cacheKey);
            $this->client->sendMessage($chatId, 'Transaction not found');

            return;
        }

        // A bare number is a correction of the value, not of the direction: keep income as income.
        if (!$hasExplicitSign) {
            $amount = (float) $transaction->amount < 0 ? -abs($amount) : abs($amount);
        }

        $transaction = $this->updateTransaction->execute($user, $transaction, [
            'amount' => $amount,
            'tags' => $transaction->tags->pluck('id')->all(),
        ]);

        Cache::forget($cacheKey);

        $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Amount updated');
    }
}
