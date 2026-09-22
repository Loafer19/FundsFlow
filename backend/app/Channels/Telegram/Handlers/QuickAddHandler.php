<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Enums\TransactionSource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class QuickAddHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
    ) {}

    public function handleQuickAdd(User $user, int|string $chatId, string $text): void
    {
        $parsed = $this->support->parseQuickAdd($text, $user);

        if ($parsed === null) {
            $this->client->sendMessage(
                $chatId,
                "Didn't recognize that. Format: -350 groceries (minus is an expense, plus is income). "
                    . 'Prefix a date like "20.08 -350 groceries" to log a past day.',
            );

            return;
        }

        if (isset($parsed['error'])) {
            $this->client->sendMessage($chatId, $parsed['error']);

            return;
        }

        $transaction = $this->createTransaction->execute($user, [
            'at' => $parsed['at'],
            'amount' => $parsed['amount'],
            'note' => $parsed['note'],
        ], TransactionSource::Telegram);

        $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Saved');
    }

    public function handleEditAmountReply(User $user, int|string $chatId, string $text): void
    {
        $cacheKey = "telegram_edit_amount:{$chatId}";
        $transactionId = (int) Cache::get($cacheKey);

        if (!preg_match('/^([+-]?\d+(?:[.,]\d{1,2})?)$/u', trim($text), $matches)) {
            $this->client->sendMessage($chatId, 'Send the new amount like +120 or -50.5');

            return;
        }

        $amount = (float) str_replace(',', '.', $matches[1]);

        if (!str_starts_with($matches[1], '+') && !str_starts_with($matches[1], '-')) {
            $amount = -abs($amount);
        }

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

        $transaction = $this->updateTransaction->execute($user, $transaction, [
            'amount' => $amount,
            'tags' => $transaction->tags->pluck('id')->all(),
        ]);

        Cache::forget($cacheKey);

        $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Amount updated');
    }
}
