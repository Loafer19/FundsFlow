<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\CreateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramHistory;
use App\Channels\Telegram\TelegramSupport;
use App\Channels\Telegram\Widgets\TelegramWidgetData;
use App\Channels\Telegram\Widgets\TelegramWidgetSender;
use App\Channels\Telegram\Widgets\TelegramWidgetSync;
use App\Enums\TransactionSource;
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
        private readonly TelegramWidgetData $data,
        private readonly TelegramWidgetSender $sender,
        private readonly TelegramWidgetSync $sync,
        private readonly TelegramHistory $history,
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
            if (preg_match('/^a:([+-]?\d{1,10}(?:[.,]\d{1,2})?)(?::(\d{1,10}))?$/', $data, $matches)) {
                $this->handleAdd($user, $chatId, $queryId, $matches);

                return;
            }

            if (preg_match('/^d:(\d{1,10})$/', $data, $matches)) {
                $this->handleBudgetDetail($user, $queryId, (int) $matches[1]);

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
        $this->sync->schedule($user);
        $this->sync->flush();
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
