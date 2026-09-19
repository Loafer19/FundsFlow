<?php

namespace App\Channels\Telegram;

use App\Actions\Tags\ListTagsAction;
use App\Models\Identity;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class TelegramSupport
{
    public const MENU_MONTH = '📊 Month';

    public const MENU_RECENT = '🕘 Recent';

    public const MENU_TAGS = '🏷 Tags';

    public const MENU_BUDGETS = '💰 Budgets';

    private const TAGS_PER_PAGE = 8;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly ListTagsAction $listTags,
    ) {}

    public function sendWelcome(int|string $chatId, string $headline): void
    {
        $this->client->sendMessage(
            $chatId,
            "{$headline}\n\n" . $this->usageInfo(),
            $this->menuKeyboard(),
        );
    }

    public function usageInfo(): string
    {
        return "Send a message like \"-350 groceries\" to log an expense, or \"+15000 salary\" for income.\n"
            . "Prefix a date for a past entry: \"20.08 -350 groceries\" (DD.MM or DD.MM.YYYY).\n"
            . "Or send a photo/PDF with that caption to attach a receipt.\n\n"
            . 'Use the menu below, or /help for all commands.';
    }

    public function sendHelp(int|string $chatId): void
    {
        $this->client->sendMessage(
            $chatId,
            "Commands\n"
            . "/month — this month's income, expenses, net, top tags\n"
            . "/analytics — same as /month\n"
            . "/recent — last 10 transactions\n"
            . "/tags — list your tags\n"
            . "/newtag — create a tag: /newtag 🍕 Fast Food > Food\n"
            . "/budgets — active budgets for the current period\n"
            . "/recurring — recurring rules\n"
            . "/app — open the FundsFlow Mini App from the menu button\n"
            . "/website — one-time web login code\n"
            . "/mute — mute budget alerts, weekly digest, recurring notifications\n"
            . "/unmute — turn notifications back on\n"
            . "/unlink — unlink Telegram (account stays on the website)\n"
            . "/help — this list\n\n"
            . "Quick-add\n"
            . "-350 groceries\n"
            . "+15000 salary\n"
            . "20.08 -350 groceries\n\n"
            . "Receipts\n"
            . 'Send a photo or PDF with a caption like "-350 groceries"',
            $this->menuKeyboard(),
        );
    }

    public function sendMiniAppHint(int|string $chatId): void
    {
        $this->client->sendMessage(
            $chatId,
            'Open the FundsFlow Mini App from the menu button next to the message field.',
            $this->menuKeyboard(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function menuKeyboard(): array
    {
        return [
            'keyboard' => [
                [self::MENU_MONTH, self::MENU_RECENT],
                [self::MENU_TAGS, self::MENU_BUDGETS],
            ],
            'resize_keyboard' => true,
        ];
    }

    public function resolveUser(int|string $chatId): ?User
    {
        return $this->resolveIdentity($chatId)?->user;
    }

    public function resolveIdentity(int|string $chatId): ?Identity
    {
        return Identity::query()
            ->where('provider', 'telegram')
            ->where('external_id', (string) $chatId)
            ->first();
    }

    public function formatTransactionLine(User $user, Transaction $transaction): string
    {
        $emoji = $transaction->amount > 0 ? '📈' : '📉';
        $note = $transaction->note ? " — {$transaction->note}" : '';
        $files = '';

        if ($transaction->relationLoaded('attachments') && $transaction->attachments->isNotEmpty()) {
            $files = ' 📎' . $transaction->attachments->count();
        }

        return sprintf(
            '%s %s%s%s (%s)',
            $emoji,
            UserFormatter::formatMoney($user, $transaction->amount),
            $note,
            $files,
            UserFormatter::formatDate($user, $transaction->at),
        );
    }

    public function formatTagList(Transaction $transaction): string
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
    public function postSaveKeyboard(Transaction $transaction): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✏️ Change tags', 'callback_data' => "retag:{$transaction->id}"],
                ['text' => '💰 Edit amount', 'callback_data' => "editamt:{$transaction->id}"],
                ['text' => '🗑 Delete', 'callback_data' => "del:{$transaction->id}"],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tagKeyboard(User $user, Transaction $transaction, int $page = 0): array
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
    public function buildTagTree(Collection $tags, ?int $parentId = null, int $depth = 0): array
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

    public function formatTagLabel(Tag $tag, int $depth, bool $selected = false): string
    {
        $prefix = $depth > 0 ? str_repeat('  ', $depth) . '↳ ' : '';
        $mark = $selected ? '✅ ' : '';

        return $prefix . $mark . trim($tag->emoji . ' ' . $tag->title);
    }

    public function findOwnTransaction(User $user, int $transactionId): ?Transaction
    {
        $transaction = Transaction::with(['tags', 'attachments'])->find($transactionId);

        if (!$transaction || $transaction->user_id !== $user->id) {
            return null;
        }

        return $transaction;
    }

    public function parseDate(string $raw): ?string
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
     * @return array{at: string, amount: float, note: ?string}|array{error: string}|null
     */
    public function parseQuickAdd(string $text, ?User $user = null): ?array
    {
        $date = $user?->todayDateString() ?? now()->toDateString();

        if (preg_match('/^(\d{1,2}\.\d{1,2}(?:\.\d{4})?)\s+(.+)$/u', $text, $dateMatch)) {
            $parsedDate = $this->parseDate($dateMatch[1]);

            if ($parsedDate === null) {
                return ['error' => "Couldn't parse that date. Use DD.MM or DD.MM.YYYY"];
            }

            $date = $parsedDate;
            $text = $dateMatch[2];
        }

        if (!preg_match('/^([+-]?\d+(?:[.,]\d{1,2})?)\s*(.*)$/u', $text, $matches)) {
            return null;
        }

        $amount = (float) str_replace(',', '.', $matches[1]);

        if (!str_starts_with($matches[1], '+') && !str_starts_with($matches[1], '-')) {
            $amount = -abs($amount);
        }

        if ($amount === 0.0) {
            return ['error' => "Amount can't be zero"];
        }

        $note = trim($matches[2]);

        if (mb_strlen($note) > 255) {
            return ['error' => 'Note is too long (255 characters max)'];
        }

        return [
            'at' => $date,
            'amount' => $amount,
            'note' => $note !== '' ? $note : null,
        ];
    }
}
