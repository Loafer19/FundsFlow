<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class CallbackHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly MenuHandler $menuHandler,
        private readonly MediaHandler $mediaHandler,
    ) {}

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'];
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $data = $callbackQuery['data'] ?? '';

        if ($data === 'noop') {
            $this->client->answerCallbackQuery($callbackId);

            return;
        }

        if (!$chatId) {
            $this->client->answerCallbackQuery($callbackId);

            return;
        }

        $user = $this->support->resolveUser($chatId);

        if (!$user) {
            $this->client->answerCallbackQuery($callbackId, "Account isn't linked.");

            return;
        }

        if (preg_match('/^tagx:(\d+):(\d+):(\d+)$/', $data, $matches)) {
            $this->handleTagToggle($user, $chatId, $messageId, $callbackId, (int) $matches[1], (int) $matches[2], (int) $matches[3]);

            return;
        }

        if (preg_match('/^tagpage:(\d+):(\d+)$/', $data, $matches)) {
            $this->handleTagPage($user, $chatId, $messageId, $callbackId, (int) $matches[1], (int) $matches[2]);

            return;
        }

        if (preg_match('/^tagclear:(\d+):(\d+)$/', $data, $matches)) {
            $this->handleTagClear($user, $chatId, $messageId, $callbackId, (int) $matches[1], (int) $matches[2]);

            return;
        }

        if (preg_match('/^tagdone:(\d+)$/', $data, $matches)) {
            $this->handleTagDone($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^open:(\d+)$/', $data, $matches)) {
            $this->handleOpen($user, $chatId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^delask:(\d+)$/', $data, $matches) || preg_match('/^del:(\d+)$/', $data, $matches)) {
            $this->handleDeleteAsk($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^delyes:(\d+)$/', $data, $matches)) {
            $this->handleDeleteYes($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^delno:(\d+)$/', $data, $matches)) {
            $this->handleDeleteNo($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^delrow:(\d+)$/', $data, $matches)) {
            // Legacy recent-list delete: open confirm instead.
            $this->handleDeleteAsk($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^retag:(\d+)$/', $data, $matches)) {
            $this->handleRetag($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^editamt:(\d+)$/', $data, $matches)) {
            $this->handleEditAmountPrompt($user, $chatId, $callbackId, (int) $matches[1]);

            return;
        }

        if ($data === 'mediaamt:-100' || $data === 'mediaamt:-500') {
            $amount = (float) substr($data, strlen('mediaamt:'));
            $this->client->answerCallbackQuery($callbackId);
            $this->mediaHandler->completePendingMediaAmount($user, $chatId, $amount);

            return;
        }

        if ($data === 'mediaamt:other') {
            Cache::put("telegram_pending_media_await_text:{$chatId}", true, now()->addMinutes(15));
            $this->client->answerCallbackQuery($callbackId);
            $this->client->sendMessage($chatId, 'Send amount like -350 groceries');

            return;
        }

        if ($data === 'mediacancel') {
            $this->mediaHandler->cancelPendingMedia($chatId);
            $this->client->answerCallbackQuery($callbackId, 'Cancelled');
            $this->client->sendMessage($chatId, 'Cancelled');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
    }

    private function handleTagToggle(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        int $transactionId,
        int $tagId,
        int $page,
    ): void {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $ids = $transaction->tags->pluck('id')->all();
        $ids = in_array($tagId, $ids, true)
            ? array_values(array_diff($ids, [$tagId]))
            : [...$ids, $tagId];

        $transaction = $this->updateTransaction->execute($user, $transaction, ['tags' => $ids]);

        $this->client->answerCallbackQuery($callbackId);
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, $page);
    }

    private function handleTagPage(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId, int $page): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, $page);
    }

    private function handleTagClear(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId, int $page): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $transaction = $this->updateTransaction->execute($user, $transaction, ['tags' => []]);

        $this->client->answerCallbackQuery($callbackId, 'Cleared');
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, $page);
    }

    private function handleTagDone(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId, 'Saved ✅');

        if ($messageId) {
            $this->client->editMessageText(
                $chatId,
                $messageId,
                $this->support->formatTransactionCard($user, $transaction, '✅ Saved'),
                $this->support->postSaveKeyboard($transaction),
                TelegramSupport::PARSE_HTML,
            );
        }
    }

    private function handleOpen(User $user, int|string $chatId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $this->support->sendSavedTransaction($chatId, $user, $transaction, 'Transaction');
    }

    private function handleDeleteAsk(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);

        if (!$messageId) {
            return;
        }

        $card = $this->support->formatTransactionCard($user, $transaction, 'Transaction');
        $text = $card . "\n\nDelete this transaction?";

        $this->client->editMessageText(
            $chatId,
            $messageId,
            $text,
            $this->support->deleteConfirmKeyboard($transactionId),
            TelegramSupport::PARSE_HTML,
        );
    }

    private function handleDeleteYes(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $line = $this->support->formatTransactionLine($user, $transaction);

        $this->deleteTransaction->execute($user, $transaction);

        $this->client->answerCallbackQuery($callbackId, 'Deleted 🗑');

        if ($messageId) {
            $this->client->editMessageText($chatId, $messageId, "🗑 Deleted\n{$line}", ['inline_keyboard' => []]);
        }
    }

    private function handleDeleteNo(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);

        if ($messageId) {
            $this->client->editMessageText(
                $chatId,
                $messageId,
                $this->support->formatTransactionCard($user, $transaction, '✅ Saved'),
                $this->support->postSaveKeyboard($transaction),
                TelegramSupport::PARSE_HTML,
            );
        }
    }

    private function handleRetag(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, 0);
    }

    private function handleEditAmountPrompt(User $user, int|string $chatId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        Cache::put("telegram_edit_amount:{$chatId}", $transaction->id, now()->addMinutes(10));

        $this->client->answerCallbackQuery($callbackId);
        $this->client->sendMessage($chatId, 'Send the new amount like +120 or -50.5');
    }

    private function renderTagPicker(User $user, int|string $chatId, ?int $messageId, Transaction $transaction, int $page): void
    {
        if (!$messageId) {
            return;
        }

        $this->client->editMessageText(
            $chatId,
            $messageId,
            $this->support->formatTransactionCard($user, $transaction, '✅ Saved'),
            $this->support->tagKeyboard($user, $transaction, $page),
            TelegramSupport::PARSE_HTML,
        );
    }
}
