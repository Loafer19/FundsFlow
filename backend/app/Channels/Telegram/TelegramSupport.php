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

    public const MENU_RECURRING = '🔁 Recurring';

    public const MENU_WEB = '🌐 Web UI';

    public const PARSE_HTML = 'HTML';

    public const RECENT_LIMIT = 5;

    public const NOTE_LIST_MAX = 28;

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
            . "Or send a photo/PDF with that caption to attach a receipt.\n"
            . "Photo without an amount: AI draft (limited per day), then Confirm.\n"
            . "Album of photos: choose one receipt or separate transactions.\n"
            . "Voice notes work too — same commands as typing (shared daily AI limit).\n"
            . "Natural language: ask about your finances, show recent/budgets/menu/tags, rename tags, month summary.\n"
            . "AI has a daily limit; Settings shows quota, and the bot says when you're out.\n\n"
            . 'Use the menu below, or /help for all commands.';
    }

    public function sendHelp(int|string $chatId): void
    {
        $this->client->sendMessage(
            $chatId,
            "Commands\n"
            . "/month — this month's income, expenses, net, top tags\n"
            . "/analytics — same as /month\n"
            . "/recent — last " . self::RECENT_LIMIT . " transactions\n"
            . "/tags — list your tags\n"
            . "/newtag — create a tag: /newtag 🍕 Fast Food > Food\n"
            . "/budgets — active budgets for the current period\n"
            . "/recurring — recurring rules\n"
            . "/app — open the Web UI from the menu button\n"
            . "/website — one-time web login code\n"
            . "/mute — mute budget alerts, weekly digest, recurring notifications\n"
            . "/unmute — turn notifications back on\n"
            . "/unlink — unlink Telegram (account stays on the website)\n"
            . "/help — this list\n\n"
            . "Reply menu\n"
            . "Month · Recent · Tags · Budgets · Recurring · Web UI\n\n"
            . "Quick-add\n"
            . "-350 groceries\n"
            . "+15000 salary\n"
            . "20.08 -350 groceries\n\n"
            . "Receipts\n"
            . "Send a photo or PDF with a caption like \"-350 groceries\" for instant save. Without an amount, AI may draft a receipt (Confirm / Edit / Cancel).\n"
            . "Albums: choose one transaction or separate.\n\n"
            . "Voice\n"
            . "Send a voice note for the same actions as typing (quick-add, menu, tags, help, month summary). Uses the shared daily AI budget.\n\n"
            . "Text AI\n"
            . "Ask free-form questions about your finances (any language). Examples: show recent, budgets, menu, tags; rename tags; month summary; what do you think about my finances.\n"
            . "Same views as the reply-menu buttons when you ask to show them.\n\n"
            . "AI limit\n"
            . "Receipt AI, voice, and text AI share a daily limit. Settings in the Web UI shows used/limit and reset time; the bot tells you when you're out.",
            $this->menuKeyboard(),
        );
    }

    public function sendMiniAppHint(int|string $chatId): void
    {
        $this->client->sendMessage(
            $chatId,
            'Open the Web UI from the menu button next to the message field.',
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
                [self::MENU_RECURRING, self::MENU_WEB],
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

    public function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function truncateNote(?string $note, int $max = self::NOTE_LIST_MAX): string
    {
        if ($note === null || $note === '') {
            return '';
        }

        if (mb_strlen($note) <= $max) {
            return $note;
        }

        return mb_substr($note, 0, max(1, $max - 1)) . '…';
    }

    public function progressBar(float $ratio, int $width = 10): string
    {
        $clamped = max(0.0, min(1.0, $ratio));
        $filled = (int) round($clamped * $width);
        $empty = max(0, $width - $filled);
        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        $pct = (int) round($ratio * 100);

        // Overspend: full bar + unclamped percent; otherwise bare bar (caller may append pct).
        if ($ratio > 1.0) {
            return str_repeat('█', $width) . " {$pct}%";
        }

        return $bar;
    }

    public function formatTagListHtml(Transaction $transaction): string
    {
        if ($transaction->tags->isEmpty()) {
            return 'No tags';
        }

        return $transaction->tags
            ->map(fn (Tag $tag) => $this->escapeHtml(trim($tag->emoji . ' ' . $tag->title)))
            ->implode(' · ');
    }

    public function formatTransactionCard(User $user, Transaction $transaction, string $headline = '✅ Saved'): string
    {
        $emoji = $transaction->amount > 0 ? '📈' : '📉';
        $money = $this->escapeHtml(UserFormatter::formatMoney($user, $transaction->amount));
        $date = $this->escapeHtml(UserFormatter::formatDate($user, $transaction->at));

        $lines = [
            $this->escapeHtml($headline),
            '',
            "<b>{$money}</b> {$emoji}",
            "📅 {$date}",
        ];

        if ($transaction->note) {
            $lines[] = '📝 ' . $this->escapeHtml($transaction->note);
        }

        if ($transaction->relationLoaded('attachments') && $transaction->attachments->isNotEmpty()) {
            $count = $transaction->attachments->count();
            $lines[] = '📎 ' . $count . ' file' . ($count === 1 ? '' : 's');
        }

        $lines[] = '🏷 ' . $this->formatTagListHtml($transaction);

        return implode("\n", $lines);
    }

    public function formatTransactionListItem(User $user, Transaction $transaction, int $index): string
    {
        $money = $this->escapeHtml(UserFormatter::formatMoney($user, $transaction->amount));
        $note = $transaction->note
            ? $this->escapeHtml($this->truncateNote($transaction->note))
            : '—';
        $date = $this->escapeHtml(UserFormatter::formatDate($user, $transaction->at));
        $firstTag = $transaction->tags->first();
        $tagLabel = $firstTag
            ? $this->escapeHtml(trim($firstTag->emoji . ' ' . $firstTag->title))
            : 'Untagged';

        $meta = "📅 {$date} · 🏷 {$tagLabel}";

        if ($transaction->relationLoaded('attachments') && $transaction->attachments->isNotEmpty()) {
            $meta .= ' · 📎';
        }

        return ($index + 1) . ". <b>{$money}</b> · {$note}\n    {$meta}";
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

    public function sendSavedTransaction(
        int|string $chatId,
        User $user,
        Transaction $transaction,
        string $headline = '✅ Saved',
    ): void {
        $this->client->sendMessage(
            $chatId,
            $this->formatTransactionCard($user, $transaction, $headline),
            $this->postSaveKeyboard($transaction),
            self::PARSE_HTML,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function postSaveKeyboard(Transaction $transaction): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🏷 Tags', 'callback_data' => "retag:{$transaction->id}"],
                ['text' => '✏️ Amount', 'callback_data' => "editamt:{$transaction->id}"],
                ['text' => '🗑', 'callback_data' => "delask:{$transaction->id}"],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteConfirmKeyboard(int $transactionId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Delete', 'callback_data' => "delyes:{$transactionId}"],
                ['text' => '↩️ Cancel', 'callback_data' => "delno:{$transactionId}"],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaAmountKeyboard(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '−100', 'callback_data' => 'mediaamt:-100'],
                ['text' => '−500', 'callback_data' => 'mediaamt:-500'],
                ['text' => 'Other', 'callback_data' => 'mediaamt:other'],
                ['text' => 'Cancel', 'callback_data' => 'mediacancel'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptDraftKeyboard(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => 'draft:ok'],
                ['text' => '✏️ Edit amount', 'callback_data' => 'draft:edit'],
                ['text' => 'Cancel', 'callback_data' => 'draft:cancel'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function albumChoiceKeyboard(string $groupId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => 'One transaction', 'callback_data' => 'album:one:' . $groupId],
                ['text' => 'Separate', 'callback_data' => 'album:each:' . $groupId],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nlCreateTagsKeyboard(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => 'nl:tags:ok'],
                ['text' => 'Cancel', 'callback_data' => 'nl:tags:cancel'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nlRenameConfirmKeyboard(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => 'nl:rename:ok'],
                ['text' => 'Cancel', 'callback_data' => 'nl:rename:cancel'],
            ]],
        ];
    }

    /**
     * @param list<array{id: int, title: string, emoji?: string}> $items
     * @return array<string, mixed>
     */
    public function nlRenamePickKeyboard(array $items): array
    {
        $buttons = [];
        foreach ($items as $item) {
            if (!isset($item['id'], $item['title'])) {
                continue;
            }

            $label = trim((string) ($item['emoji'] ?? '') . ' ' . (string) $item['title']);
            $buttons[] = [
                'text' => $label,
                'callback_data' => 'nl:rename:pick:' . (int) $item['id'],
            ];
        }

        return ['inline_keyboard' => array_chunk($buttons, 2)];
    }

    /**
     * Confirm keyboard for agent-staged mutations (transactions, budgets, …).
     *
     * @return array<string, mixed>
     */
    public function nlAgentConfirmKeyboard(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => 'nl:agent:ok'],
                ['text' => 'Cancel', 'callback_data' => 'nl:agent:cancel'],
            ]],
        ];
    }

    /**
     * Inline suggestions for ask_user (English labels). Callback index into pending suggestions.
     *
     * @param list<string> $suggestions
     * @return array<string, mixed>
     */
    public function nlAskSuggestionsKeyboard(array $suggestions): array
    {
        $buttons = [];
        foreach (array_values($suggestions) as $index => $label) {
            $buttons[] = [
                'text' => mb_substr((string) $label, 0, 64),
                'callback_data' => 'nl:ask:choice:' . $index,
            ];
        }

        return ['inline_keyboard' => array_chunk($buttons, 2)];
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function sendReceiptDraft(int|string $chatId, User $user, array $draft): void
    {
        $this->client->sendMessage(
            $chatId,
            $this->formatReceiptDraftCard($user, $draft),
            $this->receiptDraftKeyboard(),
            self::PARSE_HTML,
        );
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function formatReceiptDraftCard(User $user, array $draft): string
    {
        $amount = $draft['amount'] ?? null;
        $amount = is_numeric($amount) ? (float) $amount : null;
        $emoji = ($amount !== null && $amount > 0) ? '📈' : '📉';
        $money = $amount !== null
            ? $this->escapeHtml(UserFormatter::formatMoney($user, $amount))
            : '?';
        $at = (string) ($draft['at'] ?? $user->todayDateString());
        $date = $this->escapeHtml(UserFormatter::formatDate($user, $at));

        $lines = [
            $this->escapeHtml('🧾 Receipt draft'),
            '',
            "<b>{$money}</b> {$emoji}",
            "📅 {$date}",
        ];

        $note = $draft['note'] ?? null;
        if (is_string($note) && $note !== '') {
            $lines[] = '📝 ' . $this->escapeHtml($note);
        }

        $titles = $draft['suggested_tag_titles'] ?? [];
        if (is_array($titles) && $titles !== []) {
            $matched = $this->matchSuggestedTagTitles($user, $titles);
            if ($matched !== []) {
                $label = implode(', ', array_map(
                    fn (Tag $tag) => $this->escapeHtml(trim($tag->emoji . ' ' . $tag->title)),
                    $matched,
                ));
                $lines[] = '🏷 ' . $label;
            } else {
                $hint = implode(', ', array_map(fn ($t) => $this->escapeHtml((string) $t), $titles));
                $lines[] = '🏷 suggested: ' . $hint;
            }
        } else {
            $lines[] = '🏷 —';
        }

        $lines[] = '';
        $lines[] = 'Confirm to save with receipt, or edit the amount.';

        return implode("\n", $lines);
    }

    /**
     * Match AI-suggested titles to the user's tags (case-insensitive exact title).
     *
     * @param list<string>|mixed $titles
     * @return list<int>
     */
    public function matchSuggestedTagIds(User $user, mixed $titles): array
    {
        return array_map(
            static fn (Tag $tag) => $tag->id,
            $this->matchSuggestedTagTitles($user, $titles),
        );
    }

    /**
     * @param list<string>|mixed $titles
     * @return list<Tag>
     */
    public function matchSuggestedTagTitles(User $user, mixed $titles): array
    {
        if (!is_array($titles) || $titles === []) {
            return [];
        }

        $wanted = [];
        foreach ($titles as $title) {
            if (!is_string($title)) {
                continue;
            }
            $normalized = mb_strtolower(trim($title));
            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $matched = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $key = mb_strtolower(trim((string) $tag->title));
            if (isset($wanted[$key])) {
                $matched[] = $tag;
                unset($wanted[$key]);
            }
        }

        return $matched;
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
