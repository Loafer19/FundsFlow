<?php

namespace App\Channels\Telegram;

use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\Handlers\MediaHandler;
use App\Channels\Telegram\Widgets\TelegramWidgetData;
use App\Channels\Telegram\Widgets\TelegramWidgetSender;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TagIds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The ✏️ Edit widget (edit.html) for a saved transaction or a receipt draft, private chats only.
 *
 * Which record an editor edits is kept server-side, keyed by the editor's own message id, so its
 * queries carry only the new values (≤ 64 bytes):
 *   es:<signed amount>:<yymmdd>:<tag ids, comma-separated>   save
 *   ed                                                        delete (draft: discard)
 *   en                                                        ask for a new note in the chat
 *   et                                                        full tag picker on the card
 * After a save the card message above is edited too, so chat history matches.
 */
class TelegramEditor
{
    private const TTL_DAYS = 3;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly TelegramWidgetSender $sender,
        private readonly TelegramWidgetData $data,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly TelegramHistory $history,
    ) {}

    public function openForTransaction(User $user, int|string $chatId, Transaction $transaction, ?int $cardMessageId, string $headline): bool
    {
        $chatData = $this->data->editor(
            $user,
            't',
            (float) $transaction->amount,
            $transaction->at->toDateString(),
            $transaction->note,
            $transaction->tags->pluck('id')->all(),
        )['chat_data'];

        return $this->open($chatId, "tx:{$transaction->id}", $chatData, [
            't' => 'tx',
            'id' => (int) $transaction->id,
            'card' => $cardMessageId,
            'h' => $headline,
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function openForDraft(User $user, int|string $chatId, array $draft, ?int $cardMessageId): bool
    {
        $chatData = $this->data->editor(
            $user,
            'd',
            (float) ($draft['amount'] ?? 0),
            (string) ($draft['at'] ?? $user->todayDateString()),
            is_string($draft['note'] ?? null) ? $draft['note'] : null,
            $this->draftTagIds($user, $draft),
        )['chat_data'];

        return $this->open($chatId, 'draft', $chatData, [
            't' => 'draft',
            'nonce' => (string) ($draft['nonce'] ?? ''),
            'card' => $cardMessageId,
        ]);
    }

    /**
     * Close the editor of a transaction that is gone (🗑, ↩️ Undo, deleted elsewhere in the chat).
     */
    public function closeFor(int|string $chatId, int $transactionId, string $message = 'Transaction deleted'): void
    {
        $editorId = Cache::pull($this->targetKey($chatId, "tx:{$transactionId}"));

        if (!is_int($editorId)) {
            return;
        }

        Cache::forget($this->editorKey($chatId, $editorId));
        $this->sender->editChatData($chatId, $editorId, json_encode(['k' => 't', 'x' => $message]) ?: '{}');
    }

    /**
     * The receipt draft was confirmed or replaced: its editor shows a closed state.
     */
    public function closeDraft(int|string $chatId, string $message): void
    {
        $editorId = Cache::pull($this->targetKey($chatId, 'draft'));

        if (!is_int($editorId)) {
            return;
        }

        Cache::forget($this->editorKey($chatId, $editorId));
        $this->sender->editChatData($chatId, $editorId, json_encode(['k' => 'd', 'x' => $message]) ?: '{}');
    }

    /**
     * @return null|array<string, mixed> answer payload, null when $data isn't an editor query
     */
    public function handleQuery(User $user, int|string $chatId, ?int $editorId, string $data): ?array
    {
        if (!preg_match('/^e[sdnt](?::|$)/', $data)) {
            return null;
        }

        $ref = $editorId !== null ? Cache::get($this->editorKey($chatId, $editorId)) : null;

        if (!is_array($ref)) {
            return ['ok' => 0, 'e' => 'This editor expired. Tap ✏️ Edit again'];
        }

        if (preg_match('/^es:(-?\d{1,10}(?:\.\d{1,2})?):(\d{6}):((?:\d{1,10},?){0,6})$/', $data, $m)) {
            return $this->save($user, $chatId, $editorId, $ref, (float) $m[1], $m[2], $m[3]);
        }

        return match ($data) {
            'ed' => $this->delete($user, $chatId, $editorId, $ref),
            'en' => $this->askNote($user, $chatId, $editorId, $ref),
            'et' => $this->tagPicker($user, $chatId, $ref),
            default => ['ok' => 0, 'e' => 'Unknown request'],
        };
    }

    public function awaitingNote(int|string $chatId): bool
    {
        return Cache::has($this->noteKey($chatId));
    }

    /**
     * The typed reply after 📝 in the editor. "-" clears the note.
     */
    public function handleNoteReply(User $user, int|string $chatId, string $text): void
    {
        $editorId = Cache::pull($this->noteKey($chatId));
        $ref = is_int($editorId) ? Cache::get($this->editorKey($chatId, $editorId)) : null;

        if (!is_array($ref)) {
            $this->client->sendMessage($chatId, 'This editor expired. Tap ✏️ Edit again');

            return;
        }

        $note = trim($text);
        $note = $note === '-' || $note === '' ? null : mb_substr($note, 0, 255);

        if ($ref['t'] === 'tx') {
            $transaction = $this->support->findOwnTransaction($user, (int) $ref['id']);

            if (!$transaction) {
                $this->client->sendMessage($chatId, 'Transaction not found');

                return;
            }

            $transaction = $this->updateTransaction->execute($user, $transaction, ['note' => $note]);
            $this->afterTransactionSave($user, $chatId, $editorId, $ref, $transaction);
            $this->client->sendMessage($chatId, $note === null ? 'Note removed!' : 'Note updated!');

            return;
        }

        $draft = $this->draft($chatId, $ref);

        if ($draft === null) {
            $this->client->sendMessage($chatId, 'This receipt draft expired. Send the photo again');

            return;
        }

        $draft['note'] = $note;
        $this->afterDraftSave($user, $chatId, $editorId, $ref, $draft);
        $this->client->sendMessage($chatId, $note === null ? 'Note removed!' : 'Note updated!');
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>
     */
    private function save(User $user, int|string $chatId, int $editorId, array $ref, float $amount, string $yymmdd, string $tags): array
    {
        if ($amount === 0.0) {
            return ['ok' => 0, 'e' => "Amount can't be zero"];
        }

        if (abs($amount) > 99_999_999.99) {
            return ['ok' => 0, 'e' => 'Amount is too large'];
        }

        $date = CarbonImmutable::createFromFormat('!ymd', $yymmdd);

        if ($date === false || $date->format('ymd') !== $yymmdd) {
            return ['ok' => 0, 'e' => 'Pick a valid date'];
        }

        $at = $date->toDateString();

        if ($at > $user->todayDateString()) {
            return ['ok' => 0, 'e' => "Date can't be in the future"];
        }

        $tagIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $tags)))));

        if ($ref['t'] === 'tx') {
            $transaction = $this->support->findOwnTransaction($user, (int) $ref['id']);

            if (!$transaction) {
                return ['ok' => 0, 'e' => 'Transaction not found'];
            }

            $transaction = $this->updateTransaction->execute($user, $transaction, [
                'amount' => $amount,
                'at' => $at,
                'tags' => $tagIds,
            ]);
            $this->history->outcome($chatId, $user, 'Edited: ' . TelegramHistory::transactionLine($user, $transaction));
            $this->afterTransactionSave($user, $chatId, $editorId, $ref, $transaction);

            return ['ok' => 1, 'm' => 'Transaction updated!'];
        }

        $draft = $this->draft($chatId, $ref);

        if ($draft === null) {
            return ['ok' => 0, 'e' => 'This receipt draft expired'];
        }

        $draft['amount'] = $amount;
        $draft['at'] = $at;
        // Only the user's own tags survive (UpdateTransactionAction does the same for a transaction).
        $draft['tag_ids'] = TagIds::forSync($user, $tagIds);
        $draft['awaiting_price'] = false;
        $draft['awaiting_edit_amount'] = false;
        $draft['needs_price'] = false;
        $this->afterDraftSave($user, $chatId, $editorId, $ref, $draft);

        return ['ok' => 1, 'm' => 'Draft updated! Tap ✅ Confirm to save'];
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>
     */
    private function delete(User $user, int|string $chatId, int $editorId, array $ref): array
    {
        if ($ref['t'] === 'draft') {
            if ($this->draft($chatId, $ref) === null) {
                return ['ok' => 0, 'e' => 'This receipt draft expired'];
            }

            app(MediaHandler::class)->cancelPendingMedia($chatId);
            $this->history->outcome($chatId, $user, 'Receipt draft discarded (nothing saved).');
            $this->closeEditor($chatId, $editorId, $ref, 'd', 'Receipt discarded');

            if ($ref['card'] ?? null) {
                $this->client->editMessageText($chatId, (int) $ref['card'], '↩️ Discarded, nothing saved');
            }

            return ['ok' => 1, 'm' => 'Receipt discarded!'];
        }

        $transaction = $this->support->findOwnTransaction($user, (int) $ref['id']);

        if (!$transaction) {
            return ['ok' => 0, 'e' => 'Transaction not found'];
        }

        $card = $this->support->formatTransactionCard($user, $transaction, '🗑 Deleted');
        $remembered = TelegramHistory::transactionLine($user, $transaction);
        $this->deleteTransaction->execute($user, $transaction);
        $this->history->outcome($chatId, $user, 'Deleted: ' . $remembered);
        $this->closeEditor($chatId, $editorId, $ref, 't', 'Transaction deleted');

        if ($ref['card'] ?? null) {
            $this->client->editMessageText($chatId, (int) $ref['card'], $card, ['inline_keyboard' => []], TelegramSupport::PARSE_HTML);
        }

        return ['ok' => 1, 'm' => 'Transaction deleted!'];
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>
     */
    private function askNote(User $user, int|string $chatId, int $editorId, array $ref): array
    {
        Cache::put($this->noteKey($chatId), $editorId, now()->addMinutes(10));
        $this->client->sendMessage(
            $chatId,
            'Send the new note, or - to remove it',
            ['force_reply' => true, 'input_field_placeholder' => 'Note'],
        );

        return ['ok' => 1, 'm' => 'Type the note in the chat'];
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>
     */
    private function tagPicker(User $user, int|string $chatId, array $ref): array
    {
        $transaction = $ref['t'] === 'tx' ? $this->support->findOwnTransaction($user, (int) $ref['id']) : null;

        if (!$transaction || !($ref['card'] ?? null)) {
            return ['ok' => 0, 'e' => 'All tags work for saved transactions'];
        }

        $this->client->editMessageText(
            $chatId,
            (int) $ref['card'],
            $this->support->formatTransactionCard($user, $transaction, (string) ($ref['h'] ?? 'Transaction')),
            $this->support->tagKeyboard($user, $transaction),
            TelegramSupport::PARSE_HTML,
        );

        return ['ok' => 1, 'm' => 'Pick tags on the card above'];
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function afterTransactionSave(User $user, int|string $chatId, int $editorId, array $ref, Transaction $transaction): void
    {
        $transaction->loadMissing(['tags', 'attachments']);
        $this->sender->editChatData($chatId, $editorId, $this->data->editor(
            $user,
            't',
            (float) $transaction->amount,
            $transaction->at->toDateString(),
            $transaction->note,
            $transaction->tags->pluck('id')->all(),
        )['chat_data']);

        if ($ref['card'] ?? null) {
            $headline = (string) ($ref['h'] ?? 'Transaction');
            $headline = str_starts_with($headline, '✅ ') || $headline === 'Transaction' ? '✅ Updated' : $headline;
            $this->client->editMessageText(
                $chatId,
                (int) $ref['card'],
                $this->support->formatTransactionCard($user, $transaction, $headline),
                $this->support->postSaveKeyboard($transaction, $chatId),
                TelegramSupport::PARSE_HTML,
            );
        }
    }

    /**
     * @param array<string, mixed> $ref
     * @param array<string, mixed> $draft
     */
    private function afterDraftSave(User $user, int|string $chatId, int $editorId, array $ref, array $draft): void
    {
        MediaHandler::storeDraft($chatId, $draft);
        $this->sender->editChatData($chatId, $editorId, $this->data->editor(
            $user,
            'd',
            (float) $draft['amount'],
            (string) $draft['at'],
            is_string($draft['note'] ?? null) ? $draft['note'] : null,
            $this->draftTagIds($user, $draft),
        )['chat_data']);

        if ($ref['card'] ?? null) {
            $this->support->sendReceiptDraft($chatId, $user, $draft, (int) $ref['card']);
        }
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function closeEditor(int|string $chatId, int $editorId, array $ref, string $kind, string $message): void
    {
        Cache::forget($this->editorKey($chatId, $editorId));
        Cache::forget($this->targetKey($chatId, $ref['t'] === 'tx' ? 'tx:' . $ref['id'] : 'draft'));
        $this->sender->editChatData($chatId, $editorId, json_encode(['k' => $kind, 'x' => $message]) ?: '{}');
    }

    /**
     * @param array<string, mixed> $ref
     * @return null|array<string, mixed>
     */
    private function draft(int|string $chatId, array $ref): ?array
    {
        $draft = MediaHandler::storedDraft($chatId);

        return is_array($draft) && ($draft['nonce'] ?? null) === ($ref['nonce'] ?? '') ? $draft : null;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<int>
     */
    private function draftTagIds(User $user, array $draft): array
    {
        if (is_array($draft['tag_ids'] ?? null)) {
            return array_map('intval', $draft['tag_ids']);
        }

        return $this->support->matchSuggestedTagIds($user, $draft['suggested_tag_titles'] ?? []);
    }

    /**
     * One editor per record: opening it again removes the older editor message.
     *
     * @param array<string, mixed> $ref
     */
    private function open(int|string $chatId, string $target, string $chatData, array $ref): bool
    {
        $previous = Cache::get($this->targetKey($chatId, $target));
        $editorId = $this->sender->sendOnce($chatId, TelegramWidgetData::KIND_EDIT, $chatData, true);

        if ($editorId === null) {
            return false;
        }

        if (is_int($previous) && $previous !== $editorId) {
            Cache::forget($this->editorKey($chatId, $previous));
            $this->client->deleteMessage($chatId, $previous);
        }

        Cache::put($this->editorKey($chatId, $editorId), $ref, now()->addDays(self::TTL_DAYS));
        Cache::put($this->targetKey($chatId, $target), $editorId, now()->addDays(self::TTL_DAYS));

        return true;
    }

    private function editorKey(int|string $chatId, int $editorId): string
    {
        return "telegram_editor:{$chatId}:{$editorId}";
    }

    private function targetKey(int|string $chatId, string $target): string
    {
        return "telegram_editor_for:{$chatId}:{$target}";
    }

    private function noteKey(int|string $chatId): string
    {
        return "telegram_edit_note:{$chatId}";
    }
}
