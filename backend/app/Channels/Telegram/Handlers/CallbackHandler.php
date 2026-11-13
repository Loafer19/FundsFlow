<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\RecurringReminders;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramEditor;
use App\Channels\Telegram\TelegramHistory;
use App\Channels\Telegram\TelegramSupport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class CallbackHandler
{
    /** Text of the message whose button was tapped (its headline is kept on re-render). */
    private ?string $messageText = null;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly MenuHandler $menuHandler,
        private readonly MediaHandler $mediaHandler,
        private readonly NaturalLanguageHandler $naturalLanguageHandler,
        private readonly TelegramHistory $history,
        private readonly TelegramEditor $editor,
        private readonly RecurringReminders $reminders,
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
        $this->messageText = isset($callbackQuery['message']['text']) ? (string) $callbackQuery['message']['text'] : null;
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

        if (preg_match('/^attach:(\d+)$/', $data, $matches)) {
            $this->handleAttach($user, $chatId, $telegramUserId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^attx:(\d+)$/', $data, $matches)) {
            if (!$this->support->cancelAttach($chatId, $telegramUserId) && is_int($messageId)) {
                $this->client->editMessageText($chatId, $messageId, 'Attach cancelled');
            }
            $this->client->answerCallbackQuery($callbackId, 'Attach cancelled');

            return;
        }

        if (preg_match('/^files:(\d+)$/', $data, $matches)) {
            $this->handleFiles($user, $chatId, $callbackId, (int) $matches[1]);

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

        if (preg_match('/^undo:(\d+)$/', $data, $matches)) {
            $this->handleUndo($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^edit:(\d+)$/', $data, $matches)) {
            $this->handleOpenEditor($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^menu:(add|month|budgets|recurring)$/', $data, $matches)) {
            $this->client->answerCallbackQuery($callbackId);
            match ($matches[1]) {
                'add' => $this->menuHandler->sendAddWidget($user, $chatId),
                'month' => $this->menuHandler->sendMonthSummary($user, $chatId),
                'budgets' => $this->menuHandler->sendBudgets($user, $chatId),
                'recurring' => $this->menuHandler->sendRecurring($user, $chatId),
            };

            return;
        }

        if (preg_match('/^rec:(skip|pause|resume):(\d+)(?::(\d{8}))?$/', $data, $matches)) {
            $this->client->answerCallbackQuery(
                $callbackId,
                $this->reminders->handleButton($user, $chatId, $messageId, $matches[1], (int) $matches[2], $matches[3] ?? null),
            );

            return;
        }

        if (preg_match('/^draft:open:(' . TelegramSupport::NONCE_PATTERN . ')$/', $data, $matches)) {
            // The draft card keeps its buttons: Confirm still works next to the editor.
            $this->client->answerCallbackQuery($callbackId);
            $this->mediaHandler->openDraftEditor($user, $chatId, $matches[1], $messageId);

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
            $this->handleDraftButton($user, $chatId, $callbackId, $matches[1], $matches[2] ?? '', $messageId);

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

        if (preg_match('/^album:list:(.+)$/', $data, $matches)) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction list');
            $this->mediaHandler->handleAlbumList($user, $chatId, $matches[1], $telegramUserId);

            return;
        }

        if (preg_match('/^imp:(create|none|cancel):(' . TelegramSupport::NONCE_PATTERN . ')$/', $data, $matches)) {
            $this->removeKeyboard($chatId, $messageId);
            $this->client->answerCallbackQuery($callbackId, $this->mediaHandler->handleImportButton($user, $chatId, $telegramUserId, $matches[1], $matches[2]));

            return;
        }

        if (preg_match('/^nl:(tags|rename|agent):(ok|cancel)(?::(' . TelegramSupport::NONCE_PATTERN . '))?$/', $data, $matches)) {
            $this->removeKeyboard($chatId, $messageId);
            $this->handleNlConfirmButton($user, $chatId, $callbackId, $telegramUserId, $matches[1], $matches[2], $matches[3] ?? '', $messageId);

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
            $this->client->sendMessage($chatId, 'Send the amount, like 350 or 350 groceries');

            return;
        }

        if ($data === 'mediacancel') {
            $this->mediaHandler->cancelPendingMedia($chatId);
            $this->client->answerCallbackQuery($callbackId, 'Cancelled');
            $this->client->sendMessage($chatId, '↩️ Cancelled');
            $this->mediaHandler->continueAlbumQueue($user, $chatId);

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
    }

    private function handleDraftButton(User $user, int|string $chatId, string $callbackId, string $action, string $nonce, ?int $messageId = null): void
    {
        if ($nonce === '') {
            // Card sent before buttons carried a nonce.
            $this->client->answerCallbackQuery($callbackId, 'This card expired. Send the receipt again.');

            return;
        }

        if ($action === 'ok') {
            $this->client->answerCallbackQuery($callbackId, 'Saving…');
            $this->mediaHandler->confirmDraft($user, $chatId, $nonce, $messageId);

            return;
        }

        $this->client->answerCallbackQuery($callbackId);

        if ($action === 'edit') {
            $this->mediaHandler->beginDraftAmountEdit($user, $chatId, $nonce);

            return;
        }

        if ($this->mediaHandler->cancelDraft($chatId, $nonce)) {
            if ($messageId) {
                $this->client->editMessageText($chatId, $messageId, '↩️ Cancelled, nothing saved');
            } else {
                $this->client->sendMessage($chatId, '↩️ Cancelled, nothing saved');
            }
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
        ?int $messageId = null,
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
                'agent' => $nl->cancelAgentMutation($chatId, $nonce, $telegramUserId, $user, $messageId),
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
            'agent' => $nl->confirmAgentMutation($user, $chatId, $nonce, $telegramUserId, $messageId),
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

        $this->client->answerCallbackQuery($callbackId, 'Tags saved!');
        $this->history->outcome($chatId, $user, 'Tags set: ' . TelegramHistory::transactionLine($user, $transaction->load('tags')));
        $this->renderCard($user, $chatId, $messageId, $transaction, TelegramSupport::cardHeadline($this->messageText));
    }

    /** 📎 Attach on a group text card: the next photo or file from this member goes to the transaction. */
    private function handleAttach(User $user, int|string $chatId, int|string $sender, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        $error = $this->support->startAttach($user, $chatId, $sender, $transaction);
        $this->client->answerCallbackQuery($callbackId, $error ?? '');
    }

    private function handleFiles(User $user, int|string $chatId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction || $transaction->attachments->isEmpty()) {
            $this->client->answerCallbackQuery($callbackId, $transaction ? 'No files' : 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId, 'Sending files…');
        $result = $this->support->sendAttachments($chatId, $transaction);

        if ($result['failed'] > 0) {
            $this->client->sendMessage($chatId, "Couldn't send {$result['failed']} of " . ($result['sent'] + $result['failed']) . ' files.');
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

        // ↩️ Cancel puts the card back with its own headline (✅ Saved, ✅ Updated, …).
        Cache::put($this->headlineKey($chatId, $messageId), TelegramSupport::cardHeadline($this->messageText), now()->addDay());

        $this->client->editMessageText(
            $chatId,
            $messageId,
            $this->support->formatTransactionCard($user, $transaction, '🗑 Delete this transaction?'),
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

        $card = $this->support->formatTransactionCard($user, $transaction, '🗑 Deleted');
        $remembered = TelegramHistory::transactionLine($user, $transaction);

        $this->deleteTransaction->execute($user, $transaction);
        $this->history->outcome($chatId, $user, 'Deleted: ' . $remembered);
        $this->editor->closeFor($chatId, $transactionId);

        $this->client->answerCallbackQuery($callbackId, 'Transaction deleted!');

        if ($messageId) {
            $this->client->editMessageText($chatId, $messageId, $card, ['inline_keyboard' => []], TelegramSupport::PARSE_HTML);
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
            $headline = Cache::pull($this->headlineKey($chatId, $messageId)) ?: 'Transaction';
            $this->renderCard($user, $chatId, $messageId, $transaction, (string) $headline);
        }
    }

    /**
     * ↩️ Undo on a fresh card: deletes the entry (TransactionsChanged fires). After the window
     * the button goes away and the tap says so.
     */
    private function handleUndo(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Already deleted');
            $this->removeKeyboard($chatId, $messageId);

            return;
        }

        if (!$this->support->canUndo($transaction)) {
            $this->client->answerCallbackQuery($callbackId, 'Too late to undo. Use 🗑 Delete');
            $this->renderCard($user, $chatId, $messageId, $transaction, TelegramSupport::cardHeadline($this->messageText));

            return;
        }

        $card = $this->support->formatTransactionCard($user, $transaction, '↩️ Undone, not saved');
        $remembered = TelegramHistory::transactionLine($user, $transaction);
        $this->deleteTransaction->execute($user, $transaction);
        $this->history->outcome($chatId, $user, 'Undone (deleted): ' . $remembered);
        $this->editor->closeFor($chatId, $transactionId);
        $this->client->answerCallbackQuery($callbackId, 'Transaction undone!');

        if ($messageId) {
            $this->client->editMessageText($chatId, $messageId, $card, ['inline_keyboard' => []], TelegramSupport::PARSE_HTML);
        }
    }

    private function handleOpenEditor(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found');

            return;
        }

        if (!TelegramSupport::isPrivateChat($chatId)) {
            // Old private-chat card forwarded or a stale keyboard: fall back to the tag picker.
            $this->client->answerCallbackQuery($callbackId);
            $this->renderTagPicker($user, $chatId, $messageId, $transaction, 0);

            return;
        }

        $this->client->answerCallbackQuery($callbackId);

        if (!$this->editor->openForTransaction($user, $chatId, $transaction, $messageId, TelegramSupport::cardHeadline($this->messageText))) {
            $this->client->sendMessage($chatId, "Couldn't open the editor. Try again in a moment");
        }
    }

    /**
     * Card text and its keyboard with the given headline; ↩️ Undo stays while the entry is fresh.
     */
    private function renderCard(User $user, int|string $chatId, ?int $messageId, Transaction $transaction, string $headline): void
    {
        if (!$messageId) {
            return;
        }

        $this->client->editMessageText(
            $chatId,
            $messageId,
            $this->support->formatTransactionCard($user, $transaction, $headline),
            $this->support->postSaveKeyboard($transaction, $chatId, str_starts_with($headline, '✅ Saved')),
            TelegramSupport::PARSE_HTML,
        );
    }

    private function headlineKey(int|string $chatId, int $messageId): string
    {
        return "telegram_card_headline:{$chatId}:{$messageId}";
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
            $this->support->formatTransactionCard($user, $transaction, TelegramSupport::cardHeadline($this->messageText)),
            $this->support->tagKeyboard($user, $transaction, $page),
            TelegramSupport::PARSE_HTML,
        );
    }
}
