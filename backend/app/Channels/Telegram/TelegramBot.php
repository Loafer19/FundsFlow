<?php

namespace App\Channels\Telegram;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Enums\TransactionSource;
use App\Models\Identity;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramBot
{
    private const MENU_MONTH = '📊 Month';

    private const MENU_RECENT = '🕘 Recent';

    private const MENU_TAGS = '🏷 Tags';

    private const TAGS_PER_PAGE = 8;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly ListTagsAction $listTags,
        private readonly ListTransactionsAction $listTransactions,
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly CreateTagAction $createTag,
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

    public function sendWelcome(int|string $chatId): void
    {
        $this->client->sendMessage(
            $chatId,
            "✅ Linked to your FundsFlow account!\n\n" . $this->usageInfo(),
            $this->menuKeyboard(),
        );
    }

    private function usageInfo(): string
    {
        return "Send a message like \"-350 groceries\" to log an expense, or \"+15000 salary\" for income.\n"
            . "Prefix a date for a past entry: \"20.08 -350 groceries\" (DD.MM or DD.MM.YYYY).\n\n"
            . 'Use the menu below, or /month, /recent, /tags, /newtag.';
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text']);

        if (str_starts_with($text, '/start')) {
            $this->handleStart($message);

            return;
        }

        $user = $this->resolveUser($chatId);

        if (!$user) {
            $this->client->sendMessage($chatId, "Your account isn't linked yet. Send /start to get a linking code.");

            return;
        }

        match (true) {
            $text === '/month' || $text === self::MENU_MONTH => $this->sendMonthSummary($user, $chatId),
            $text === '/tags' || $text === self::MENU_TAGS => $this->sendTags($user, $chatId),
            $text === '/recent' || $text === self::MENU_RECENT => $this->sendRecent($user, $chatId),
            str_starts_with($text, '/newtag') => $this->handleNewTag($user, $chatId, $text),
            default => $this->handleQuickAdd($user, $chatId, $text),
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleStart(array $message): void
    {
        $chatId = $message['chat']['id'];

        if ($this->resolveUser($chatId)) {
            $this->client->sendMessage(
                $chatId,
                "You're already linked to your FundsFlow account.\n\n" . $this->usageInfo(),
                $this->menuKeyboard(),
            );

            return;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put("telegram_link:{$code}", [
            'chat_id' => $chatId,
            'username' => $message['from']['username'] ?? null,
            'first_name' => $message['from']['first_name'] ?? null,
        ], now()->addMinutes(10));

        $this->client->sendMessage(
            $chatId,
            "Linking code: {$code}\n\nEnter it in FundsFlow → Settings → Telegram within 10 minutes.",
        );
    }

    private function handleQuickAdd(User $user, int|string $chatId, string $text): void
    {
        $date = now()->toDateString();

        if (preg_match('/^(\d{1,2}\.\d{1,2}(?:\.\d{4})?)\s+(.+)$/u', $text, $dateMatch)) {
            $parsedDate = $this->parseDate($dateMatch[1]);

            if ($parsedDate === null) {
                $this->client->sendMessage($chatId, "Couldn't parse that date. Use DD.MM or DD.MM.YYYY.");

                return;
            }

            $date = $parsedDate;
            $text = $dateMatch[2];
        }

        if (!preg_match('/^([+-]?\d+(?:[.,]\d{1,2})?)\s*(.*)$/u', $text, $matches)) {
            $this->client->sendMessage(
                $chatId,
                "Didn't recognize that. Format: -350 groceries (minus is an expense, plus is income). "
                    . 'Prefix a date like "20.08 -350 groceries" to log a past day.',
            );

            return;
        }

        $amount = (float) str_replace(',', '.', $matches[1]);

        if (!str_starts_with($matches[1], '+') && !str_starts_with($matches[1], '-')) {
            $amount = -abs($amount);
        }

        if ($amount === 0.0) {
            $this->client->sendMessage($chatId, "Amount can't be zero.");

            return;
        }

        $note = trim($matches[2]);

        if (mb_strlen($note) > 255) {
            $this->client->sendMessage($chatId, 'Note is too long (255 characters max).');

            return;
        }

        $transaction = $this->createTransaction->execute($user, [
            'at' => $date,
            'amount' => $amount,
            'note' => $note !== '' ? $note : null,
        ], TransactionSource::Telegram);

        $this->client->sendMessage(
            $chatId,
            "✅ Saved\n" . $this->formatTransactionLine($transaction) . "\n" . $this->formatTagList($transaction),
            $this->tagKeyboard($user, $transaction),
        );
    }

    private function parseDate(string $raw): ?string
    {
        $normalized = substr_count($raw, '.') === 1 ? $raw . '.' . now()->format('Y') : $raw;

        try {
            $date = Carbon::createFromFormat('d.m.Y', $normalized);
        } catch (Throwable) {
            return null;
        }

        if (!$date || $date->format('d.m.Y') !== $normalized) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery): void
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

        $user = $this->resolveUser($chatId);

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

        if (preg_match('/^del:(\d+)$/', $data, $matches)) {
            $this->handleDelete($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^delrow:(\d+)$/', $data, $matches)) {
            $this->handleDeleteRow($user, $callbackId, (int) $matches[1]);

            return;
        }

        if (preg_match('/^retag:(\d+)$/', $data, $matches)) {
            $this->handleRetag($user, $chatId, $messageId, $callbackId, (int) $matches[1]);

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
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

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
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, $page);
    }

    private function handleTagClear(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId, int $page): void
    {
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

            return;
        }

        $transaction = $this->updateTransaction->execute($user, $transaction, ['tags' => []]);

        $this->client->answerCallbackQuery($callbackId, 'Cleared');
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, $page);
    }

    private function handleTagDone(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

            return;
        }

        $this->client->answerCallbackQuery($callbackId, 'Saved ✅');

        if ($messageId) {
            $this->client->editMessageText(
                $chatId,
                $messageId,
                "✅ Saved\n" . $this->formatTransactionLine($transaction) . "\n" . $this->formatTagList($transaction),
                [
                    'inline_keyboard' => [[
                        ['text' => '✏️ Change tags', 'callback_data' => "retag:{$transaction->id}"],
                        ['text' => '🗑 Delete', 'callback_data' => "del:{$transaction->id}"],
                    ]],
                ],
            );
        }
    }

    private function handleDelete(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

            return;
        }

        $line = $this->formatTransactionLine($transaction);

        $this->deleteTransaction->execute($user, $transaction);

        $this->client->answerCallbackQuery($callbackId, 'Deleted 🗑');

        if ($messageId) {
            $this->client->editMessageText($chatId, $messageId, "🗑 Deleted\n{$line}", ['inline_keyboard' => []]);
        }
    }

    private function handleDeleteRow(User $user, string $callbackId, int $transactionId): void
    {
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

            return;
        }

        $this->deleteTransaction->execute($user, $transaction);

        $this->client->answerCallbackQuery($callbackId, 'Deleted 🗑');
    }

    private function handleRetag(User $user, int|string $chatId, ?int $messageId, string $callbackId, int $transactionId): void
    {
        $transaction = $this->findOwnTransaction($user, $transactionId);

        if (!$transaction) {
            $this->client->answerCallbackQuery($callbackId, 'Transaction not found.');

            return;
        }

        $this->client->answerCallbackQuery($callbackId);
        $this->renderTagPicker($user, $chatId, $messageId, $transaction, 0);
    }

    private function renderTagPicker(User $user, int|string $chatId, ?int $messageId, Transaction $transaction, int $page): void
    {
        if (!$messageId) {
            return;
        }

        $this->client->editMessageText(
            $chatId,
            $messageId,
            "✅ Saved\n" . $this->formatTransactionLine($transaction) . "\n" . $this->formatTagList($transaction),
            $this->tagKeyboard($user, $transaction, $page),
        );
    }

    private function findOwnTransaction(User $user, int $transactionId): ?Transaction
    {
        $transaction = Transaction::with('tags')->find($transactionId);

        if (!$transaction || $transaction->user_id !== $user->id) {
            return null;
        }

        return $transaction;
    }

    private function sendMonthSummary(User $user, int|string $chatId): void
    {
        $transactions = $this->listTransactions->execute($user)
            ->filter(fn (Transaction $transaction) => $transaction->at->isCurrentMonth());

        $income = $transactions->filter(fn (Transaction $transaction) => $transaction->amount > 0)->sum('amount');
        $expense = $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0)->sum('amount');

        $this->client->sendMessage($chatId, sprintf(
            "📊 %s\nIncome: +%.2f\nExpenses: %.2f\nNet: %.2f",
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
            $this->client->sendMessage($chatId, 'No tags yet.');

            return;
        }

        $lines = array_map(
            fn (array $node) => $this->formatTagLabel($node['tag'], $node['depth']),
            $this->buildTagTree($tags),
        );

        $this->client->sendMessage($chatId, "🏷 Your tags\n" . implode("\n", $lines));
    }

    private function sendRecent(User $user, int|string $chatId): void
    {
        $transactions = $this->listTransactions->execute($user)->take(10)->values();

        if ($transactions->isEmpty()) {
            $this->client->sendMessage($chatId, 'No transactions yet.');

            return;
        }

        $lines = $transactions->map(
            fn (Transaction $transaction, int $index) => ($index + 1) . ') ' . $this->formatTransactionLine($transaction),
        )->all();

        $buttons = $transactions->map(fn (Transaction $transaction, int $index) => [
            'text' => '🗑 ' . ($index + 1),
            'callback_data' => "delrow:{$transaction->id}",
        ])->all();

        $this->client->sendMessage(
            $chatId,
            "🕘 Recent transactions\n" . implode("\n", $lines),
            ['inline_keyboard' => array_chunk($buttons, 5)],
        );
    }

    private function handleNewTag(User $user, int|string $chatId, string $text): void
    {
        $payload = trim(substr($text, strlen('/newtag')));

        if ($payload === '') {
            $this->client->sendMessage(
                $chatId,
                "Usage: /newtag <emoji> <title> [> <parent title>]\nExample: /newtag 🍕 Fast Food > Food",
            );

            return;
        }

        $parentTitle = null;

        if (str_contains($payload, '>')) {
            [$payload, $parentTitle] = array_map('trim', explode('>', $payload, 2));
        }

        if (!preg_match('/^(\S+)\s+(.+)$/u', $payload, $matches)) {
            $this->client->sendMessage($chatId, 'Usage: /newtag <emoji> <title> [> <parent title>]');

            return;
        }

        $emoji = $matches[1];
        $title = trim($matches[2]);
        $parentId = null;

        if ($parentTitle !== null) {
            $parent = $this->listTags->execute($user)
                ->first(fn (Tag $tag) => mb_strtolower($tag->title) === mb_strtolower($parentTitle));

            if (!$parent) {
                $this->client->sendMessage($chatId, "Parent tag \"{$parentTitle}\" not found.");

                return;
            }

            $parentId = $parent->id;
        }

        $tag = $this->createTag->execute($user, [
            'title' => $title,
            'emoji' => $emoji,
            'parent_id' => $parentId,
            'calc_balance' => true,
        ]);

        $this->client->sendMessage($chatId, "✅ Tag created: {$tag->emoji} {$tag->title}");
    }

    private function formatTransactionLine(Transaction $transaction): string
    {
        $emoji = $transaction->amount > 0 ? '📈' : '📉';
        $note = $transaction->note ? " — {$transaction->note}" : '';

        return sprintf('%s %.2f%s (%s)', $emoji, $transaction->amount, $note, $transaction->at->format('d.m'));
    }

    private function formatTagList(Transaction $transaction): string
    {
        if ($transaction->tags->isEmpty()) {
            return '🏷 No tags';
        }

        $labels = $transaction->tags->map(fn (Tag $tag) => trim($tag->emoji . ' ' . $tag->title))->all();

        return '🏷 ' . implode(', ', $labels);
    }

    /**
     * @return array<string, mixed>
     */
    private function tagKeyboard(User $user, Transaction $transaction, int $page = 0): array
    {
        $tree = $this->buildTagTree($this->listTags->execute($user));
        $pages = array_chunk($tree, self::TAGS_PER_PAGE) ?: [[]];
        $page = max(0, min($page, count($pages) - 1));
        $selectedIds = $transaction->tags->pluck('id')->all();

        $rows = array_map(fn (array $node) => [[
            'text' => $this->formatTagLabel($node['tag'], $node['depth'], in_array($node['tag']->id, $selectedIds, true)),
            'callback_data' => "tagx:{$transaction->id}:{$node['tag']->id}:{$page}",
        ]], $pages[$page]);

        if (count($pages) > 1) {
            $navRow = [];

            if ($page > 0) {
                $navRow[] = ['text' => '◀️', 'callback_data' => "tagpage:{$transaction->id}:" . ($page - 1)];
            }

            $navRow[] = ['text' => ($page + 1) . '/' . count($pages), 'callback_data' => 'noop'];

            if ($page < count($pages) - 1) {
                $navRow[] = ['text' => '▶️', 'callback_data' => "tagpage:{$transaction->id}:" . ($page + 1)];
            }

            $rows[] = $navRow;
        }

        $rows[] = [
            ['text' => '🚫 Clear all', 'callback_data' => "tagclear:{$transaction->id}:{$page}"],
            ['text' => '✅ Done', 'callback_data' => "tagdone:{$transaction->id}"],
        ];
        $rows[] = [['text' => '🗑 Delete', 'callback_data' => "del:{$transaction->id}"]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param Collection<int, Tag> $tags
     * @return array<int, array{tag: Tag, depth: int}>
     */
    private function buildTagTree(Collection $tags, ?int $parentId = null, int $depth = 0): array
    {
        $children = $tags
            ->filter(fn (Tag $tag) => $tag->parent_id === $parentId)
            ->sortBy('title')
            ->values();

        $result = [];

        foreach ($children as $child) {
            $result[] = ['tag' => $child, 'depth' => $depth];
            $result = array_merge($result, $this->buildTagTree($tags, $child->id, $depth + 1));
        }

        return $result;
    }

    private function formatTagLabel(Tag $tag, int $depth, bool $selected = false): string
    {
        $prefix = $depth > 0 ? str_repeat('  ', $depth) . '↳ ' : '';
        $mark = $selected ? '✅ ' : '';

        return $prefix . $mark . trim($tag->emoji . ' ' . $tag->title);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuKeyboard(): array
    {
        return [
            'keyboard' => [
                [self::MENU_MONTH, self::MENU_RECENT],
                [self::MENU_TAGS],
            ],
            'resize_keyboard' => true,
        ];
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
