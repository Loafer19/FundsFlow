<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramHistory;
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
        private readonly NaturalLanguageHandler $naturalLanguageHandler,
        private readonly TelegramHistory $history,
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
        $telegramUserId = isset($callbackQuery['from']['id']) ? (int) $callbackQuery['from']['id'] : $chatId;

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

        if (preg_match('/^delask:(\d+)$/', $data, $matches)) {
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

        if (preg_match('/^retag:(\d+)$/', $data, $matches)) {
            $this->handleRetag($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^editamt:(\d+)$/', $data, $matches)) {
            $this->handleEditAmountPrompt($user, $chatId, $callbackId, (int) $matches[1]);

            return;
        }

        // Confirm-style buttons carry a nonce for the exact card they were sent
        // with. Each one is single-use, so its keyboard is removed once tapped.
        if (preg_match('/^draft:(ok|edit|cancel)(?::(' . TelegramSupport::NONCE_PATTERN . '))?$/', $data, $matches)) {
            $this->removeKeyboard($chatId, $messageId);
            $this->handleDraftButton($user, $chatId, $callbackId, $matches[1], $matches[2] ?? '');

            return;
        }

        if (preg_match('/^album:one:(.+)$/', $data, $matches)) {
            $this->client->answerCallbackQuery($callbackId, 'One transaction');
            $this->mediaHandler->handleAlbumOne($user, $chatId, $matches[1]);

            return;
        }

        if (preg_match('/^album:each:(.+)$/', $data, $matches)) {
            $this->client->answerCallbackQuery($callbackId, 'Separate');
            $this->mediaHandler->handleAlbumEach($user, $chatId, $matches[1]);

            return;
        }

        if (preg_match('/^nl:(tags|rename|agent):(ok|cancel)(?::(' . TelegramSupport::NONCE_PATTERN . '))?$/', $data, $matches)) {
            $this->removeKeyboard($chatId, $messageId);
            $this->handleNlConfirmButton($user, $chatId, $callbackId, $telegramUserId, $matches[1], $matches[2], $matches[3] ?? '');

            return;
        }

        if (preg_match('/^nl:ask:choice:(\d+)$/', $data, $matches)) {
            $this->naturalLanguageHandler->handleAskChoice(
                $user,
                $chatId,
                (int) $matches[1],
                $callbackId,
                $telegramUserId,
            );

            return;
        }

        if ($data === 'mediaamt:-100' || $data === 'mediaamt:-500') {
            $amount = (float) substr($data, strlen('mediaamt:'));
            $this->client->answerCallbackQuery($callbackId);
            $this->mediaHandler->completePendingMediaAmount($user, $chatId, $amount);

            return;
        }

        if ($data === 'mediaamt:other') {
            Cache::put("telegram_pending_media_await_text:{$chatId}", true, now()->addMinutes((int) config('telegram.pending_ttl_minutes')));
            $this->client->answerCallbackQuery($callbackId);
            $this->client->sendMessage($chatId, 'Send amount like -350 groceries');

            return;
        }

        if ($data === 'mediacancel') {
            $this->mediaHandler->cancelPendingMedia($chatId);
            $this->client->answerCallbackQuery($callbackId, 'Cancelled');
            $this->client->sendMessage($chatId, 'Cancelled');
            $this->mediaHandler->continueAlbumQueue($user, $chatId);

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
    }

    private function handleDraftButton(User $user, int|string $chatId, string $callbackId, string $action, string $nonce): void
    {
        if ($nonce === '') {
            // Card sent before buttons carried a nonce.
            $this->client->answerCallbackQuery($callbackId, 'This card expired. Send the receipt again.');

            return;
        }

        if ($action === 'ok') {
            $this->client->answerCallbackQuery($callbackId, 'Saving…');
            $this->mediaHandler->confirmDraft($user, $chatId, $nonce);

            return;
        }

        $this->client->answerCallbackQuery($callbackId);

        if ($action === 'edit') {
            $this->mediaHandler->beginDraftAmountEdit($user, $chatId, $nonce);

            return;
        }

        if ($this->mediaHandler->cancelDraft($chatId, $nonce)) {
            $this->client->sendMessage($chatId, 'Cancelled');
            $this->history->outcome($chatId, $user, 'Receipt draft cancelled (nothing saved).');
            $this->mediaHandler->continueAlbumQueue($user, $chatId);
        }
    }

    private function handleNlConfirmButton(
        User $user,
        int|string $chatId,
        string $callbackId,
        int|string|null $telegramUserId,
        string $kind,
        string $action,
        string $nonce,
    ): void {
        if ($nonce === '') {
            // Preview sent before buttons carried a nonce.
            $this->client->answerCallbackQuery($callbackId, 'This preview expired. Ask again.');

            return;
        }

        $nl = $this->naturalLanguageHandler;

        if ($action === 'cancel') {
            $this->client->answerCallbackQuery($callbackId);

            match ($kind) {
                'tags' => $nl->cancelCreateTags($chatId, $nonce, $telegramUserId, $user),
                'rename' => $nl->cancelRenameTags($chatId, $nonce, $telegramUserId, $user),
                'agent' => $nl->cancelAgentMutation($chatId, $nonce, $telegramUserId, $user),
            };

            return;
        }

        $this->client->answerCallbackQuery($callbackId, match ($kind) {
            'tags' => 'Creating…',
            'rename' => 'Renaming…',
            'agent' => 'Applying…',
        });

        match ($kind) {
            'tags' => $nl->confirmCreateTags($user, $chatId, $nonce, $telegramUserId),
            'rename' => $nl->confirmRenameTags($user, $chatId, $nonce, $telegramUserId),
            'agent' => $nl->confirmAgentMutation($user, $chatId, $nonce, $telegramUserId),
        };
    }

    private function removeKeyboard(int|string $chatId, ?int $messageId): void
    {
        if ($messageId !== null) {
            $this->client->editMessageReplyMarkup($chatId, $messageId);
        }
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
        $this->history->outcome($chatId, $user, 'Tags set: ' . TelegramHistory::transactionLine($user, $transaction->load('tags')));

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
        $this->support->sendSavedTransaction($chatId, $user, $transaction, 'Transaction', remember: false);
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
        $remembered = TelegramHistory::transactionLine($user, $transaction);

        $this->deleteTransaction->execute($user, $transaction);
        $this->history->outcome($chatId, $user, 'Deleted: ' . $remembered);

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
