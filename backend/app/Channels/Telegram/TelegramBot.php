<?php

namespace App\Channels\Telegram;

use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Models\Identity;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TelegramBot
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly ListTagsAction $listTags,
        private readonly ListTransactionsAction $listTransactions,
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);

            return;
        }

        if (isset($update['message']['text'])) {
            $this->handleMessage($update['message']);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text']);

        if (str_starts_with($text, '/start')) {
            $this->handleStart($chatId);

            return;
        }

        $user = $this->resolveUser($chatId);

        if (!$user) {
            $this->client->sendMessage($chatId, "Акаунт ще не привʼязано. Напиши /start, щоб отримати код прив'язки.");

            return;
        }

        match ($text) {
            '/balance' => $this->sendBalance($user, $chatId),
            '/tags' => $this->sendTags($user, $chatId),
            default => $this->handleQuickAdd($user, $chatId, $text),
        };
    }

    private function handleStart(int|string $chatId): void
    {
        if ($this->resolveUser($chatId)) {
            $this->client->sendMessage($chatId, 'Цей чат вже привʼязано до акаунту FundsFlow.');

            return;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put("telegram_link:{$code}", $chatId, now()->addMinutes(10));

        $this->client->sendMessage(
            $chatId,
            "Код прив'язки: {$code}\n\nВведи його в FundsFlow → Налаштування → Telegram протягом 10 хвилин.",
        );
    }

    private function handleQuickAdd(User $user, int|string $chatId, string $text): void
    {
        if (!preg_match('/^([+-]?\d+(?:[.,]\d{1,2})?)\s*(.*)$/u', $text, $matches)) {
            $this->client->sendMessage($chatId, 'Не розпізнав. Формат: -350 продукти (мінус — витрата, плюс — дохід).');

            return;
        }

        $amount = (float) str_replace(',', '.', $matches[1]);

        if (!str_starts_with($matches[1], '+') && !str_starts_with($matches[1], '-')) {
            $amount = -abs($amount);
        }

        if ($amount === 0.0) {
            $this->client->sendMessage($chatId, 'Сума не може бути нулем.');

            return;
        }

        $note = trim($matches[2]);

        if (mb_strlen($note) > 255) {
            $this->client->sendMessage($chatId, 'Нотатка задовга (максимум 255 символів).');

            return;
        }

        $transaction = $this->createTransaction->execute($user, [
            'at' => now()->toDateString(),
            'amount' => $amount,
            'note' => $note !== '' ? $note : null,
        ]);

        $this->client->sendMessage(
            $chatId,
            sprintf(
                '%s %.2f%s',
                $amount > 0 ? '📈' : '📉',
                $amount,
                $note !== '' ? " — {$note}" : '',
            ),
            $this->tagKeyboard($user, $transaction->id),
        );
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'];
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $data = $callbackQuery['data'] ?? '';

        if (!$chatId || !preg_match('/^tag:(\d+):(\d+)$/', $data, $matches)) {
            $this->client->answerCallbackQuery($callbackId);

            return;
        }

        $user = $this->resolveUser($chatId);

        if (!$user) {
            $this->client->answerCallbackQuery($callbackId, "Акаунт не привʼязано.");

            return;
        }

        $transaction = Transaction::find((int) $matches[1]);

        if (!$transaction || $transaction->user_id !== $user->id) {
            $this->client->answerCallbackQuery($callbackId, 'Транзакцію не знайдено.');

            return;
        }

        $tagId = (int) $matches[2];

        $this->updateTransaction->execute($user, $transaction, [
            'tags' => $tagId > 0 ? [$tagId] : [],
        ]);

        $this->client->answerCallbackQuery($callbackId, 'Збережено ✅');
        $this->client->editMessageReplyMarkup($chatId, $callbackQuery['message']['message_id']);
    }

    private function sendBalance(User $user, int|string $chatId): void
    {
        $transactions = $this->listTransactions->execute($user)
            ->filter(fn (Transaction $transaction) => $transaction->at->isCurrentMonth());

        $income = $transactions->filter(fn (Transaction $transaction) => $transaction->amount > 0)->sum('amount');
        $expense = $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0)->sum('amount');

        $this->client->sendMessage($chatId, sprintf(
            "📊 %s\nДохід: +%.2f\nВитрати: %.2f\nБаланс: %.2f",
            now()->format('m.Y'),
            $income,
            $expense,
            $income + $expense,
        ));
    }

    private function sendTags(User $user, int|string $chatId): void
    {
        $tags = $this->listTags->execute($user);

        if ($tags->isEmpty()) {
            $this->client->sendMessage($chatId, 'Тегів ще немає.');

            return;
        }

        $lines = $tags->map(fn (Tag $tag) => trim($tag->emoji . ' ' . $tag->title))->all();

        $this->client->sendMessage($chatId, implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function tagKeyboard(User $user, int $transactionId): array
    {
        $tags = $this->listTags->execute($user);

        $buttons = $tags->map(fn (Tag $tag) => [
            'text' => trim($tag->emoji . ' ' . $tag->title),
            'callback_data' => "tag:{$transactionId}:{$tag->id}",
        ])->all();

        $rows = array_chunk($buttons, 2);
        $rows[] = [['text' => '— Без тегу —', 'callback_data' => "tag:{$transactionId}:0"]];

        return ['inline_keyboard' => $rows];
    }

    private function resolveUser(int|string $chatId): ?User
    {
        return Identity::query()
            ->where('provider', 'telegram')
            ->where('external_id', (string) $chatId)
            ->first()
            ?->user;
    }
}
