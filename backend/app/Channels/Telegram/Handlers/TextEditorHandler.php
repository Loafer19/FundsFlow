<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramHistory;
use App\Channels\Telegram\TelegramSupport;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Text-mode editor used in groups and in private chats while Telegram refuses widgets:
 * date, note, amount, expense/income, tags, and type, all edited on the card in place.
 */
class TextEditorHandler
{
    private const PENDING_TTL_MINUTES = 10;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly TelegramHistory $history,
    ) {}

    /**
     * @return bool true when $data is a text-editor callback
     */
    public function handleCallback(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $data,
        ?string $messageText,
    ): bool {
        $n = TelegramSupport::NONCE_PATTERN;

        if (preg_match('/^edate:(\d+)(?::([012t]))?$/', $data, $m)) {
            $this->onDate($user, $chatId, $messageId, $callbackId, 'tx', (int) $m[1], $m[2] ?? null, $messageText);

            return true;
        }

        if (preg_match('/^enote:(\d+)(?::([xc]))?$/', $data, $m)) {
            $this->onNote($user, $chatId, $messageId, $callbackId, 'tx', (int) $m[1], $m[2] ?? null, $messageText);

            return true;
        }

        if (preg_match('/^esign:(\d+):([ei])$/', $data, $m)) {
            $this->onSign($user, $chatId, $messageId, $callbackId, 'tx', (int) $m[1], $m[2] === 'i', $messageText);

            return true;
        }

        if (preg_match('/^etype:(\d+)(?::(\d+))?$/', $data, $m)) {
            $this->onType($user, $chatId, $messageId, $callbackId, 'tx', (int) $m[1], $m[2] ?? null, $messageText);

            return true;
        }

        if (preg_match('/^etypep:(\d+):(\d+)$/', $data, $m)) {
            $this->onTypePage($user, $chatId, $messageId, $callbackId, 'tx', (int) $m[1], (int) $m[2], $messageText);

            return true;
        }

        if (preg_match('/^eback:(\d+)$/', $data, $m)) {
            $this->onBack($user, $chatId, $messageId, $callbackId, 'tx', (int) $m[1], $messageText);

            return true;
        }

        if (preg_match("/^draft:date:({$n})(?::([012t]))?$/", $data, $m)) {
            $this->onDate($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $m[2] ?? null, $messageText);

            return true;
        }

        if (preg_match("/^draft:note:({$n})(?::([xc]))?$/", $data, $m)) {
            $this->onNote($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $m[2] ?? null, $messageText);

            return true;
        }

        if (preg_match("/^draft:sign:({$n}):([ei])$/", $data, $m)) {
            $this->onSign($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $m[2] === 'i', $messageText);

            return true;
        }

        if (preg_match("/^draft:type:({$n})(?::(\\d+))?$/", $data, $m)) {
            $this->onType($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $m[2] ?? null, $messageText);

            return true;
        }

        if (preg_match("/^draft:typep:({$n}):(\\d+)$/", $data, $m)) {
            $this->onTypePage($user, $chatId, $messageId, $callbackId, 'draft', $m[1], (int) $m[2], $messageText);

            return true;
        }

        if (preg_match("/^draft:tags:({$n})$/", $data, $m)) {
            $this->onDraftTags($user, $chatId, $messageId, $callbackId, $m[1], 0);

            return true;
        }

        if (preg_match("/^dtagx:({$n}):(\\d+):(\\d+)$/", $data, $m)) {
            $this->onDraftTagToggle($user, $chatId, $messageId, $callbackId, $m[1], (int) $m[2], (int) $m[3]);

            return true;
        }

        if (preg_match("/^dtagp:({$n}):(\\d+)$/", $data, $m)) {
            $this->onDraftTags($user, $chatId, $messageId, $callbackId, $m[1], (int) $m[2]);

            return true;
        }

        if (preg_match("/^dtagc:({$n}):(\\d+)$/", $data, $m)) {
            $this->onDraftTagClear($user, $chatId, $messageId, $callbackId, $m[1], (int) $m[2]);

            return true;
        }

        if (preg_match("/^dtagd:({$n})$/", $data, $m)) {
            $this->onBack($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $messageText, 'Tags saved!');

            return true;
        }

        if (preg_match("/^draft:(?:amt|edit):({$n})$/", $data, $m)) {
            $this->onAmount($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $messageText);

            return true;
        }

        if (preg_match("/^draft:back:({$n})$/", $data, $m)) {
            $this->onBack($user, $chatId, $messageId, $callbackId, 'draft', $m[1], $messageText);

            return true;
        }

        return false;
    }

    public function awaiting(int|string $chatId): bool
    {
        return Cache::has($this->pendingKey($chatId));
    }

    public function handleTypedReply(User $user, int|string $chatId, string $text): void
    {
        $pending = $this->pending($chatId);

        if ($pending === null) {
            return;
        }

        $kind = $pending['kind'] ?? '';
        $scope = $pending['scope'] ?? '';
        $id = $pending['id'] ?? null;
        $messageId = isset($pending['message_id']) ? (int) $pending['message_id'] : null;
        $headline = is_string($pending['headline'] ?? null) ? $pending['headline'] : null;

        if ($kind === 'amount') {
            $this->applyTypedAmount($user, $chatId, $text, $scope, $id, $messageId, $headline);

            return;
        }

        if ($kind === 'date') {
            $this->applyTypedDate($user, $chatId, $text, $scope, $id, $messageId, $headline);

            return;
        }

        if ($kind === 'note') {
            $this->applyTypedNote($user, $chatId, $text, $scope, $id, $messageId, $headline);
        }
    }

    /**
     * ✏️ Amount on a saved card: the card itself asks for the new amount.
     */
    public function beginAmountEdit(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        int $transactionId,
        ?string $messageText,
    ): void {
        $this->onAmount($user, $chatId, $messageId, $callbackId, 'tx', $transactionId, $messageText);
    }

    public static function pendingKey(int|string $chatId): string
    {
        return "telegram_text_pending:{$chatId}";
    }

    private function onDate(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        ?string $choice,
        ?string $messageText,
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        if ($choice === 't') {
            $this->rememberPending($chatId, 'date', $scope, $id, $messageId, $messageText);
            $this->client->answerCallbackQuery($callbackId);
            $this->render($user, $chatId, $messageId, $target, $messageText, $this->support->dateTypeKeyboard($scope, $id), 'Send a date as DD.MM.YYYY');

            return;
        }

        if ($choice === '0' || $choice === '1' || $choice === '2') {
            $at = $user->nowInTimezone()->startOfDay()->subDays((int) $choice)->toDateString();
            $this->forgetPending($chatId);
            $result = $this->setDate($user, $chatId, $target, $at);

            if (is_string($result)) {
                $this->client->answerCallbackQuery($callbackId, $result);

                return;
            }

            $this->client->answerCallbackQuery($callbackId, 'Date updated!');
            $this->render($user, $chatId, $messageId, $result, $messageText, $this->mainKeyboard($user, $chatId, $result, $messageText));

            return;
        }

        $this->forgetPending($chatId);
        $this->client->answerCallbackQuery($callbackId);
        $current = $scope === 'tx' ? $target['transaction']->at->toDateString() : (string) ($target['draft']['at'] ?? $user->todayDateString());
        $this->render($user, $chatId, $messageId, $target, $messageText, $this->support->dateEditKeyboard($scope, $id, $current, $user->todayDateString()));
    }

    private function onNote(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        ?string $choice,
        ?string $messageText,
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        if ($choice === 'c') {
            $this->onBack($user, $chatId, $messageId, $callbackId, $scope, $id, $messageText);

            return;
        }

        if ($choice === 'x') {
            $this->forgetPending($chatId);
            $result = $this->setNote($user, $chatId, $target, null);
            $this->client->answerCallbackQuery($callbackId, 'Note removed!');
            $this->render($user, $chatId, $messageId, $result, $messageText, $this->mainKeyboard($user, $chatId, $result, $messageText));

            return;
        }

        $this->rememberPending($chatId, 'note', $scope, $id, $messageId, $messageText);
        $this->client->answerCallbackQuery($callbackId);
        $this->render($user, $chatId, $messageId, $target, $messageText, $this->support->noteEditKeyboard($scope, $id), 'Send the new note');
    }

    private function onAmount(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        ?string $messageText,
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        if ($scope === 'draft') {
            $draft = $target['draft'];
            $draft['awaiting_edit_amount'] = true;
            $draft['awaiting_price'] = false;
            MediaHandler::storeDraft($chatId, $draft);
            $target['draft'] = $draft;
        }

        $this->rememberPending($chatId, 'amount', $scope, $id, $messageId, $messageText);
        $this->client->answerCallbackQuery($callbackId);
        $this->render($user, $chatId, $messageId, $target, $messageText, $this->support->amountEditKeyboard($scope, $id), 'Send the new amount like +120 or -50.5');
    }

    private function onSign(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        bool $income,
        ?string $messageText,
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        $amount = $scope === 'tx'
            ? (float) $target['transaction']->amount
            : (isset($target['draft']['amount']) && is_numeric($target['draft']['amount']) ? (float) $target['draft']['amount'] : null);

        if ($amount === null) {
            $this->client->answerCallbackQuery($callbackId, 'Set an amount first');

            return;
        }

        $new = $income ? abs($amount) : -abs($amount);

        if ($new === $amount) {
            $this->client->answerCallbackQuery($callbackId, $income ? 'Already income' : 'Already an expense');

            return;
        }

        $result = $this->setAmount($user, $chatId, $target, $new);
        $this->client->answerCallbackQuery($callbackId, $income ? 'Switched to income!' : 'Switched to expense!');
        $this->render($user, $chatId, $messageId, $result, $messageText, $this->mainKeyboard($user, $chatId, $result, $messageText));
    }

    private function onType(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        ?string $typeId,
        ?string $messageText,
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        if ($typeId !== null) {
            $result = $this->setType($user, $chatId, $target, (int) $typeId);

            if (is_string($result)) {
                $this->client->answerCallbackQuery($callbackId, $result);

                return;
            }

            $toast = 'Type updated!';
            $type = (int) $typeId > 0 ? $user->transactionTypes()->find((int) $typeId) : null;

            if ($type instanceof TransactionType && $type->visibleFields() !== []) {
                $toast = 'Type updated! Change fields in the web app or ask the assistant';
            }

            $this->client->answerCallbackQuery($callbackId, $toast);
            $this->render($user, $chatId, $messageId, $result, $messageText, $this->mainKeyboard($user, $chatId, $result, $messageText));

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $current = $scope === 'tx'
            ? $target['transaction']->transaction_type_id
            : ($target['draft']['transaction_type_id'] ?? null);
        $this->render($user, $chatId, $messageId, $target, $messageText, $this->support->typeEditKeyboard($user, $scope, $id, $current !== null ? (int) $current : null, 0));
    }

    private function onTypePage(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        int $page,
        ?string $messageText,
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $current = $scope === 'tx'
            ? $target['transaction']->transaction_type_id
            : ($target['draft']['transaction_type_id'] ?? null);
        $this->render($user, $chatId, $messageId, $target, $messageText, $this->support->typeEditKeyboard($user, $scope, $id, $current !== null ? (int) $current : null, $page));
    }

    private function onDraftTags(User $user, int|string $chatId, ?int $messageId, string $callbackId, string $nonce, int $page): void
    {
        $target = $this->load($user, $chatId, 'draft', $nonce);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, 'This receipt draft expired');

            return;
        }

        $this->ensureDraftTagIds($user, $chatId, $target);
        $this->client->answerCallbackQuery($callbackId);
        $this->render(
            $user,
            $chatId,
            $messageId,
            $target,
            null,
            $this->support->tagPickerKeyboard($user, $target['draft']['tag_ids'] ?? [], $page, $nonce, draft: true),
        );
    }

    private function onDraftTagToggle(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $nonce,
        int $tagId,
        int $page,
    ): void {
        $target = $this->load($user, $chatId, 'draft', $nonce);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, 'This receipt draft expired');

            return;
        }

        $this->ensureDraftTagIds($user, $chatId, $target);
        $ids = array_map('intval', $target['draft']['tag_ids'] ?? []);
        $ids = in_array($tagId, $ids, true)
            ? array_values(array_diff($ids, [$tagId]))
            : [...$ids, $tagId];
        $target['draft']['tag_ids'] = $ids;
        MediaHandler::storeDraft($chatId, $target['draft']);
        $this->client->answerCallbackQuery($callbackId);
        $this->render($user, $chatId, $messageId, $target, null, $this->support->tagPickerKeyboard($user, $ids, $page, $nonce, draft: true));
    }

    private function onDraftTagClear(User $user, int|string $chatId, ?int $messageId, string $callbackId, string $nonce, int $page): void
    {
        $target = $this->load($user, $chatId, 'draft', $nonce);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, 'This receipt draft expired');

            return;
        }

        $target['draft']['tag_ids'] = [];
        $target['draft']['suggested_tag_titles'] = [];
        MediaHandler::storeDraft($chatId, $target['draft']);
        $this->client->answerCallbackQuery($callbackId, 'Cleared');
        $this->render($user, $chatId, $messageId, $target, null, $this->support->tagPickerKeyboard($user, [], $page, $nonce, draft: true));
    }

    private function onBack(
        User $user,
        int|string $chatId,
        ?int $messageId,
        string $callbackId,
        string $scope,
        int|string $id,
        ?string $messageText,
        string $toast = '',
    ): void {
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->client->answerCallbackQuery($callbackId, $scope === 'draft' ? 'This receipt draft expired' : 'Transaction not found');

            return;
        }

        if ($scope === 'draft') {
            $draft = $target['draft'];
            $draft['awaiting_edit_amount'] = false;
            MediaHandler::storeDraft($chatId, $draft);
            $target['draft'] = $draft;
        }

        $this->forgetPending($chatId);
        $toast === '' ? $this->client->answerCallbackQuery($callbackId) : $this->client->answerCallbackQuery($callbackId, $toast);
        $this->render($user, $chatId, $messageId, $target, $messageText, $this->mainKeyboard($user, $chatId, $target, $messageText));
    }

    private function applyTypedAmount(
        User $user,
        int|string $chatId,
        string $text,
        string $scope,
        mixed $id,
        ?int $messageId,
        ?string $headline,
    ): void {
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

        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->forgetPending($chatId);
            $this->client->sendMessage($chatId, $scope === 'draft' ? 'This receipt draft expired. Send the photo again' : 'Transaction not found');

            return;
        }

        if (!$hasExplicitSign) {
            $current = $scope === 'tx'
                ? (float) $target['transaction']->amount
                : (isset($target['draft']['amount']) && is_numeric($target['draft']['amount']) ? (float) $target['draft']['amount'] : -1);
            $amount = $current < 0 ? -abs($amount) : abs($amount);
        }

        $result = $this->setAmount($user, $chatId, $target, $amount);
        $this->forgetPending($chatId);
        $this->render($user, $chatId, $messageId, $result, $headline, $this->mainKeyboard($user, $chatId, $result, $headline));

        if ($messageId === null) {
            $this->client->sendMessage($chatId, 'Amount updated!');
        }
    }

    private function applyTypedDate(
        User $user,
        int|string $chatId,
        string $text,
        string $scope,
        mixed $id,
        ?int $messageId,
        ?string $headline,
    ): void {
        $at = $this->support->parseDate(trim($text));

        if ($at === null) {
            $this->client->sendMessage($chatId, "Couldn't parse that date. Use DD.MM or DD.MM.YYYY");

            return;
        }

        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->forgetPending($chatId);
            $this->client->sendMessage($chatId, $scope === 'draft' ? 'This receipt draft expired. Send the photo again' : 'Transaction not found');

            return;
        }

        $result = $this->setDate($user, $chatId, $target, $at);

        if (is_string($result)) {
            $this->client->sendMessage($chatId, $result);

            return;
        }

        $this->forgetPending($chatId);
        $this->render($user, $chatId, $messageId, $result, $headline, $this->mainKeyboard($user, $chatId, $result, $headline));
    }

    private function applyTypedNote(
        User $user,
        int|string $chatId,
        string $text,
        string $scope,
        mixed $id,
        ?int $messageId,
        ?string $headline,
    ): void {
        $note = trim($text);
        $note = $note === '-' || $note === '' ? null : mb_substr($note, 0, 255);
        $target = $this->load($user, $chatId, $scope, $id);

        if ($target === null) {
            $this->forgetPending($chatId);
            $this->client->sendMessage($chatId, $scope === 'draft' ? 'This receipt draft expired. Send the photo again' : 'Transaction not found');

            return;
        }

        $result = $this->setNote($user, $chatId, $target, $note);
        $this->forgetPending($chatId);
        $this->render($user, $chatId, $messageId, $result, $headline, $this->mainKeyboard($user, $chatId, $result, $headline));
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     * @return array{scope: string, transaction?: Transaction, draft?: array<string, mixed>}|string
     */
    private function setDate(User $user, int|string $chatId, array $target, string $at): array|string
    {
        if ($at > $user->todayDateString()) {
            return "Date can't be in the future";
        }

        if ($target['scope'] === 'tx') {
            $transaction = $this->updateTransaction->execute($user, $target['transaction'], ['at' => $at]);
            $this->history->outcome($chatId, $user, 'Date set: ' . TelegramHistory::transactionLine($user, $transaction));

            return ['scope' => 'tx', 'transaction' => $transaction];
        }

        $draft = $target['draft'];
        $draft['at'] = $at;
        MediaHandler::storeDraft($chatId, $draft);

        return ['scope' => 'draft', 'draft' => $draft];
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     * @return array{scope: string, transaction?: Transaction, draft?: array<string, mixed>}
     */
    private function setNote(User $user, int|string $chatId, array $target, ?string $note): array
    {
        if ($target['scope'] === 'tx') {
            $transaction = $this->updateTransaction->execute($user, $target['transaction'], ['note' => $note]);
            $this->history->outcome($chatId, $user, 'Note set: ' . TelegramHistory::transactionLine($user, $transaction));

            return ['scope' => 'tx', 'transaction' => $transaction];
        }

        $draft = $target['draft'];
        $draft['note'] = $note;
        MediaHandler::storeDraft($chatId, $draft);

        return ['scope' => 'draft', 'draft' => $draft];
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     * @return array{scope: string, transaction?: Transaction, draft?: array<string, mixed>}
     */
    private function setAmount(User $user, int|string $chatId, array $target, float $amount): array
    {
        if ($target['scope'] === 'tx') {
            $transaction = $this->updateTransaction->execute($user, $target['transaction'], ['amount' => $amount]);
            $this->history->outcome($chatId, $user, 'Amount set: ' . TelegramHistory::transactionLine($user, $transaction));

            return ['scope' => 'tx', 'transaction' => $transaction];
        }

        $draft = $target['draft'];
        $draft['amount'] = $amount;
        $draft['awaiting_edit_amount'] = false;
        $draft['awaiting_price'] = false;
        $draft['needs_price'] = false;
        MediaHandler::storeDraft($chatId, $draft);

        return ['scope' => 'draft', 'draft' => $draft];
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     * @return array{scope: string, transaction?: Transaction, draft?: array<string, mixed>}|string
     */
    private function setType(User $user, int|string $chatId, array $target, int $typeId): array|string
    {
        $typeId = $typeId > 0 ? $typeId : 0;

        if ($target['scope'] === 'tx') {
            try {
                $transaction = $this->updateTransaction->execute(
                    $user,
                    $target['transaction'],
                    ['transaction_type_id' => $typeId > 0 ? $typeId : null],
                    requireAllFields: false,
                );
            } catch (ValidationException $e) {
                return collect($e->errors())->flatten()->first() ?: 'Could not set the type';
            }

            $this->history->outcome($chatId, $user, 'Type set: ' . TelegramHistory::transactionLine($user, $transaction));

            return ['scope' => 'tx', 'transaction' => $transaction];
        }

        $draft = $target['draft'];

        if ($typeId === 0) {
            unset($draft['transaction_type_id'], $draft['data']);
        } else {
            $type = $user->transactionTypes()->find($typeId);

            if (!$type instanceof TransactionType) {
                return 'Type not found';
            }

            if ((int) ($draft['transaction_type_id'] ?? 0) !== $typeId) {
                unset($draft['data']);
            }
            $draft['transaction_type_id'] = $typeId;
            $ids = array_map('intval', $draft['tag_ids'] ?? $this->support->matchSuggestedTagIds($user, $draft['suggested_tag_titles'] ?? []));

            if ($ids === [] && $type->defaultTagIds() !== []) {
                $draft['tag_ids'] = $type->defaultTagIds();
            }
        }

        MediaHandler::storeDraft($chatId, $draft);

        return ['scope' => 'draft', 'draft' => $draft];
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     */
    private function ensureDraftTagIds(User $user, int|string $chatId, array &$target): void
    {
        if (is_array($target['draft']['tag_ids'] ?? null)) {
            return;
        }

        $matched = $this->support->matchSuggestedTagIds($user, $target['draft']['suggested_tag_titles'] ?? []);
        $typeId = isset($target['draft']['transaction_type_id']) && is_numeric($target['draft']['transaction_type_id']) ? (int) $target['draft']['transaction_type_id'] : 0;
        $type = $typeId > 0 ? $user->transactionTypes()->find($typeId) : null;
        $target['draft']['tag_ids'] = array_values(array_unique([...$matched, ...($type instanceof TransactionType ? $type->defaultTagIds() : [])]));
        MediaHandler::storeDraft($chatId, $target['draft']);
    }

    /**
     * @return null|array{scope: string, transaction?: Transaction, draft?: array<string, mixed>}
     */
    private function load(User $user, int|string $chatId, string $scope, mixed $id): ?array
    {
        if ($scope === 'tx') {
            $transaction = $this->support->findOwnTransaction($user, (int) $id);

            return $transaction ? ['scope' => 'tx', 'transaction' => $transaction] : null;
        }

        $draft = MediaHandler::storedDraft($chatId);

        if (!is_array($draft) || (string) ($draft['nonce'] ?? '') !== (string) $id) {
            return null;
        }

        return ['scope' => 'draft', 'draft' => $draft];
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     * @param array<string, mixed> $keyboard
     */
    private function render(
        User $user,
        int|string $chatId,
        ?int $messageId,
        array $target,
        ?string $messageText,
        array $keyboard,
        ?string $hint = null,
    ): void {
        $text = $this->cardText($user, $target, $messageText);

        if ($hint !== null) {
            $text .= "\n\n" . $this->support->escapeHtml($hint);
        }

        if ($messageId !== null) {
            $this->client->editMessageText($chatId, $messageId, $text, $keyboard, TelegramSupport::PARSE_HTML);

            return;
        }

        $this->client->sendMessage($chatId, $text, $keyboard, TelegramSupport::PARSE_HTML);
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     */
    private function cardText(User $user, array $target, ?string $messageText): string
    {
        if ($target['scope'] === 'tx') {
            return $this->support->formatTransactionCard(
                $user,
                $target['transaction'],
                TelegramSupport::cardHeadline($messageText),
            );
        }

        return $this->support->formatReceiptDraftCard($user, $target['draft']);
    }

    /**
     * @param array{scope: string, transaction?: Transaction, draft?: array<string, mixed>} $target
     * @return array<string, mixed>
     */
    private function mainKeyboard(User $user, int|string $chatId, array $target, ?string $messageText): array
    {
        if ($target['scope'] === 'tx') {
            $undo = str_starts_with(TelegramSupport::cardHeadline($messageText), '✅ Saved');

            return $this->support->textEditKeyboard($target['transaction'], $undo, $user);
        }

        return $this->support->receiptDraftKeyboard((string) ($target['draft']['nonce'] ?? ''), $chatId, $user, $target['draft']);
    }

    private function rememberPending(
        int|string $chatId,
        string $kind,
        string $scope,
        int|string $id,
        ?int $messageId,
        ?string $messageText,
    ): void {
        Cache::put($this->pendingKey($chatId), [
            'kind' => $kind,
            'scope' => $scope,
            'id' => $id,
            'message_id' => $messageId,
            'headline' => $messageText,
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));
    }

    /** @return null|array<string, mixed> */
    private function pending(int|string $chatId): ?array
    {
        $state = Cache::get($this->pendingKey($chatId));

        return is_array($state) ? $state : null;
    }

    private function forgetPending(int|string $chatId): void
    {
        Cache::forget($this->pendingKey($chatId));
    }
}
