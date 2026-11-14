<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\RecurringTransactions\UpdateRecurringTransactionAction;
use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\DeleteTransactionAttachmentAction;
use App\Channels\Telegram\PinnedMonthSummary;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramEditor;
use App\Channels\Telegram\TelegramHistory;
use App\Channels\Telegram\TelegramSupport;
use App\Channels\Telegram\Widgets\TelegramWidgetData;
use App\Channels\Telegram\Widgets\TelegramWidgetSender;
use App\Channels\Telegram\Widgets\TelegramWidgetSync;
use App\Enums\TransactionSource;
use App\Events\TransactionsChanged;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\User;
use App\Support\UserFormatter;
use Throwable;

class WidgetQueryHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly CreateTransactionAction $createTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly TelegramWidgetData $data,
        private readonly TelegramWidgetSender $sender,
        private readonly TelegramHistory $history,
        private readonly UpdateRecurringTransactionAction $updateRecurring,
        private readonly TelegramWidgetSync $sync,
        private readonly TelegramEditor $editor,
        private readonly PinnedMonthSummary $pinned,
        private readonly DeleteTransactionAttachmentAction $deleteAttachment,
    ) {}

    /**
     * @param array<string, mixed> $query
     */
    public function handle(array $query): void
    {
        $queryId = (string) ($query['id'] ?? '');

        if ($queryId === '') {
            return;
        }

        $type = $query['type'] ?? null;
        $kind = is_array($type) ? (string) ($type['type'] ?? 'data') : 'data';

        if ($kind !== 'data') {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Unsupported request']);

            return;
        }

        $chatId = $query['chat']['id'] ?? $query['from']['id'] ?? null;
        $data = (string) ($query['data'] ?? '');
        $user = $chatId === null ? null : $this->support->resolveUser($chatId);

        if (!$user) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Account is not linked']);

            return;
        }

        try {
            $messageId = isset($query['message_id']) ? (int) $query['message_id'] : null;
            $edited = $this->editor->handleQuery($user, $chatId, $messageId, $data);

            if ($edited !== null) {
                $this->answer($queryId, $edited);

                return;
            }

            if (preg_match('/^a:([+-]?\d{1,10}(?:[.,]\d{1,2})?)(?::(\d{1,10}))?$/', $data, $matches)) {
                $this->handleAdd($user, $chatId, $queryId, $matches);

                return;
            }

            if (preg_match('/^d:(\d{1,10})$/', $data, $matches)) {
                $this->handleBudgetDetail($user, $queryId, (int) $matches[1]);

                return;
            }

            if (preg_match('/^x:(\d{1,10})$/', $data, $matches)) {
                $this->handleRecentDelete($user, $chatId, $queryId, (int) $matches[1]);

                return;
            }

            // e: is ✏️ Edit; o: is the old Open button on Recent widgets already in chats,
            // which now opens the editor too instead of a separate text card.
            if (preg_match('/^v:(\d{1,10})$/', $data, $matches)) {
                // Detail opened (0 = back to the list): a photo sent as a reply to this widget attaches there.
                if ($messageId !== null) {
                    TelegramSupport::rememberRecentOpen($chatId, $messageId, (int) $matches[1]);
                }
                $this->answer($queryId, ['ok' => 1]);

                return;
            }

            if (preg_match('/^p:(\d{1,10})$/', $data, $matches)) {
                $transaction = $this->support->findOwnTransaction($user, (int) $matches[1]);
                $error = $transaction
                    ? $this->support->startAttach($user, $chatId, $query['from']['id'] ?? $chatId, $transaction)
                    : 'Transaction not found';
                $this->answer($queryId, $error === null ? ['ok' => 1] : ['ok' => 0, 'e' => $error]);

                return;
            }

            if (preg_match('/^l:(\d{1,10})$/', $data, $matches)) {
                // 📎 Files box: the transaction's files (own transactions only).
                $transaction = $this->support->findOwnTransaction($user, (int) $matches[1]);
                $this->answer($queryId, $transaction
                    ? ['ok' => 1, 'l' => $this->support->attachmentList($transaction)]
                    : ['ok' => 0, 'e' => 'Transaction not found']);

                return;
            }

            if (preg_match('/^y:(\d{1,10})$/', $data, $matches)) {
                // /recent detail of a typed transaction: its field values as [label, value] rows.
                $transaction = $this->support->findOwnTransaction($user, (int) $matches[1]);
                $this->answer($queryId, $transaction
                    ? ['ok' => 1, 'r' => $this->data->typeRows($user, $transaction)]
                    : ['ok' => 0, 'e' => 'Transaction not found']);

                return;
            }

            if (preg_match('/^g:(\d{1,10}):(\d{1,10})$/', $data, $matches)) {
                // Send in the 📎 Files box: g:<attachment id>:<transaction id>, just that file.
                $transaction = $this->support->findOwnTransaction($user, (int) $matches[2]);
                $exists = $transaction?->attachments->contains('id', (int) $matches[1]);

                if (!$exists) {
                    $this->answer($queryId, ['ok' => 0, 'e' => $transaction ? 'File not found' : 'Transaction not found']);

                    return;
                }

                $this->answer($queryId, ['ok' => 1]);
                $result = $this->support->sendAttachments($chatId, $transaction, (int) $matches[1]);

                if ($result['failed'] > 0) {
                    $this->client->sendMessage($chatId, "Couldn't send that file.");
                }

                return;
            }

            if (preg_match('/^k:(\d{1,10}):(\d{1,10})$/', $data, $matches)) {
                // 🗑 → Yes in the 📎 Files box: k:<attachment id>:<transaction id>. Answers the new list.
                $transaction = $this->support->findOwnTransaction($user, (int) $matches[2]);
                $attachment = $transaction?->attachments->firstWhere('id', (int) $matches[1]);

                if (!$attachment) {
                    $this->answer($queryId, ['ok' => 0, 'e' => $transaction ? 'File not found' : 'Transaction not found']);

                    return;
                }

                $this->deleteAttachment->execute($user, $transaction, $attachment);
                $transaction->load('attachments');
                $this->answer($queryId, ['ok' => 1, 'l' => $this->support->attachmentList($transaction)]);
                // 📎 counts in /recent and the group cards.
                TransactionsChanged::dispatch((int) $user->id, 'updated', [(int) $transaction->id]);

                return;
            }

            // Send-all from widgets posted before the 📎 Files box (and the group cards' files: twin).
            if (preg_match('/^f:(\d{1,10})$/', $data, $matches)) {
                $this->handleRecentFiles($user, $chatId, $queryId, (int) $matches[1]);

                return;
            }

            if (preg_match('/^[eo]:(\d{1,10})$/', $data, $matches)) {
                $this->handleRecentEdit($user, $chatId, $queryId, (int) $matches[1]);

                return;
            }

            if (preg_match('/^m:(\d{1,10})$/', $data, $matches)) {
                $this->handleRecentMore($user, $queryId, (int) $matches[1]);

                return;
            }

            if (preg_match('/^t:(\d{1,10})$/', $data, $matches)) {
                $this->handleTagDetail($user, $queryId, (int) $matches[1]);

                return;
            }

            if (preg_match('/^r:(\d{1,10}):([01])$/', $data, $matches)) {
                $this->handleRecurringToggle($user, $chatId, $queryId, (int) $matches[1], $matches[2] === '1');

                return;
            }

            // n:0 / n:1 from notifications widgets sent before the per-switch layout.
            if (preg_match('/^n:(?:([mrp]):)?([01])$/', $data, $matches)) {
                $this->handleNotifications($user, $chatId, $queryId, $matches[1] ?: 'm', $matches[2] === '1');

                return;
            }
        } catch (Throwable $e) {
            report($e);
            $this->answer($queryId, ['ok' => 0, 'e' => 'Something went wrong']);

            return;
        }

        $this->answer($queryId, ['ok' => 0, 'e' => 'Unknown request']);
    }

    /**
     * @param array<int, string> $matches
     */
    private function handleAdd(User $user, int|string $chatId, string $queryId, array $matches): void
    {
        $raw = str_replace(',', '.', $matches[1]);
        $amount = (float) $raw;

        if ($amount === 0.0) {
            $this->answer($queryId, ['ok' => 0, 'e' => "Amount can't be zero"]);

            return;
        }

        $tagId = isset($matches[2]) ? (int) $matches[2] : null;
        $tag = null;

        if ($tagId) {
            $tag = $user->tags()->whereKey($tagId)->first();

            if (!$tag instanceof Tag) {
                $this->answer($queryId, ['ok' => 0, 'e' => 'Tag not found']);

                return;
            }
        }

        $transaction = $this->createTransaction->execute($user, [
            'at' => $user->todayDateString(),
            'amount' => $amount,
            'note' => null,
            'tags' => $tag ? [$tag->id] : [],
        ], TransactionSource::Telegram);

        $label = $tag ? trim($tag->emoji . ' ' . $tag->title) : 'Untagged';
        $amountLabel = $this->formatWidgetAmount($user, (float) $transaction->amount);
        $this->answer($queryId, ['ok' => 1, 'a' => $amountLabel, 'n' => $label]);

        $identity = $this->sender->identityFor($user, $chatId);

        if ($identity) {
            $this->data->storeAddLast($identity, ['a' => $amountLabel, 'n' => $label]);
        }

        $this->history->outcome($chatId, $user, 'Saved: ' . TelegramHistory::transactionLine($user, $transaction));
    }

    private function handleBudgetDetail(User $user, string $queryId, int $budgetId): void
    {
        $item = $this->data->budgetDetail($user, $budgetId);

        if ($item === null) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Budget not found']);

            return;
        }

        $this->answer($queryId, ['ok' => 1] + $item);
    }

    private function handleRecentDelete(User $user, int|string $chatId, string $queryId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Transaction not found']);

            return;
        }

        $remembered = TelegramHistory::transactionLine($user, $transaction);
        $this->deleteTransaction->execute($user, $transaction);
        $this->history->outcome($chatId, $user, 'Deleted: ' . $remembered);
        $this->editor->closeFor($chatId, $transactionId);
        $this->answer($queryId, ['ok' => 1]);
    }

    /**
     * 📎 Files in the Recent widget: the attachments as photos / documents below the widget.
     */
    private function handleRecentFiles(User $user, int|string $chatId, string $queryId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction || $transaction->attachments->isEmpty()) {
            $this->answer($queryId, ['ok' => 0, 'e' => $transaction ? 'No files' : 'Transaction not found']);

            return;
        }

        // Answer first: uploading several files can take longer than the widget waits.
        $count = $transaction->attachments->count();
        $this->answer($queryId, ['ok' => 1]);
        $result = $this->support->sendAttachments($chatId, $transaction);

        if ($result['failed'] > 0) {
            $this->client->sendMessage($chatId, "Couldn't send {$result['failed']} of {$count} files.");
        }
    }

    /**
     * ✏️ Edit in the Recent widget: the same edit.html editor as a saved card's ✏️ Edit.
     */
    private function handleRecentEdit(User $user, int|string $chatId, string $queryId, int $transactionId): void
    {
        $transaction = $this->support->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Transaction not found']);

            return;
        }

        $transaction->loadMissing('tags');
        $opened = $this->editor->openForTransaction($user, $chatId, $transaction, null, 'Transaction');
        $this->answer($queryId, $opened ? ['ok' => 1] : ['ok' => 0, 'e' => "Couldn't open the editor"]);
    }

    private function handleRecentMore(User $user, string $queryId, int $offset): void
    {
        $page = $this->data->recentPage($user, max(0, $offset));
        $this->answer($queryId, ['ok' => 1] + $page);
    }

    private function handleTagDetail(User $user, string $queryId, int $tagId): void
    {
        $detail = $this->data->tagDetail($user, $tagId);

        if ($detail === null) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Tag not found']);

            return;
        }

        $this->answer($queryId, ['ok' => 1] + $detail);
    }

    private function handleRecurringToggle(User $user, int|string $chatId, string $queryId, int $ruleId, bool $active): void
    {
        $rule = $user->recurringTransactions()->with('tags')->whereKey($ruleId)->first();

        if (!$rule instanceof RecurringTransaction) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Recurring rule not found']);

            return;
        }

        if ($rule->active !== $active) {
            $rule = $this->updateRecurring->execute($user, $rule, ['active' => $active]);
            $this->history->outcome(
                $chatId,
                $user,
                ($active ? 'Resumed' : 'Paused') . ' recurring rule: ' . UserFormatter::formatMoney($user, (float) $rule->amount)
                    . ($rule->note ? ' ' . $rule->note : ''),
            );
        }

        $this->answer($queryId, ['ok' => 1] + $this->data->recurringItem($user, $rule));
        $this->sync->refreshRecurring($user);
    }

    /**
     * m: mute everything (1 = muted), r: recurring reminders, p: pinned month summary.
     */
    private function handleNotifications(User $user, int|string $chatId, string $queryId, string $switch, bool $on): void
    {
        $identity = $this->sender->identityFor($user, $chatId);

        if (!$identity) {
            $this->answer($queryId, ['ok' => 0, 'e' => 'Chat is not linked']);

            return;
        }

        match ($switch) {
            'm' => $identity->update(['meta' => [...($identity->meta ?? []), 'muted' => $on]]),
            'r' => $identity->update(['meta' => [...($identity->meta ?? []), 'remind_off' => !$on]]),
            'p' => $on ? $this->pinned->enable($user, $identity) : $this->pinned->disable($identity),
        };

        $state = json_decode($this->data->notifications($identity->fresh() ?? $identity)['chat_data'], true);
        $this->answer($queryId, ['ok' => 1] + (is_array($state) ? $state : []));
        $this->sync->refreshNotifications($user);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function answer(string $queryId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":0}';

        if (strlen($json) > 1024) {
            $json = json_encode(['ok' => $payload['ok'] ?? 0, 'e' => $payload['e'] ?? 'Too much data']) ?: '{"ok":0}';
        }
        $this->client->answerWidgetQuery($queryId, $json);
    }

    private function formatWidgetAmount(User $user, float $amount): string
    {
        $formatted = UserFormatter::formatMoney($user, $amount);

        if ($amount > 0 && !str_starts_with($formatted, '+')) {
            return '+' . $formatted;
        }

        return $formatted;
    }
}
