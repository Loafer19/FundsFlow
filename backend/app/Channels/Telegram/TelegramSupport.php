<?php

namespace App\Channels\Telegram;

use App\Actions\Tags\ListTagsAction;
use App\Actions\Tags\MatchTagTitlesAction;
use App\Models\Identity;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\QuickAddParser;
use App\Support\TelegramAiQuota;
use App\Support\UserFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TelegramSupport
{
    public const MENU_ADD = '➕ Add';

    public const MENU_MONTH = '📊 Month';

    public const MENU_RECENT = '🕘 Recent';

    public const MENU_TAGS = '🏷 Tags';

    public const MENU_BUDGETS = '💰 Budgets';

    public const MENU_RECURRING = '🔁 Recurring';

    /**
     * Legacy button from the v1 keyboard (the Telegram menu button next to the input opens the
     * Web UI). Still handled for chats that have the old keyboard: the reply carries the new one.
     */
    public const MENU_WEB = '🌐 Web UI';

    /**
     * Bump when menuKeyboard() changes: the next widget sent to a private chat whose identity has
     * an older menu_v attaches the new keyboard once.
     */
    public const MENU_VERSION = 2;

    public const PARSE_HTML = 'HTML';

    /**
     * The bot command menu (setMyCommands), most used first. Shortcuts that still work but are
     * not listed: /mute, /unmute (→ /notifications),
     * /newtag, /app, /new (→ /reset).
     */
    public const BOT_COMMANDS = [
        ['command' => 'add', 'description' => 'Quick add a transaction with the keypad'],
        ['command' => 'recent', 'description' => 'Recent transactions: open, edit tags, delete'],
        ['command' => 'month', 'description' => 'This month: income, expenses, net, top tags'],
        ['command' => 'budgets', 'description' => 'Spending limits and savings goals'],
        ['command' => 'tags', 'description' => "Your tags with this month's expenses and income"],
        ['command' => 'recurring', 'description' => 'Recurring payments: next dates, pause or resume'],
        ['command' => 'notifications', 'description' => 'Alerts, digest, reminders and pinned month'],
        ['command' => 'website', 'description' => 'One-time code to log in on the website'],
        ['command' => 'reset', 'description' => 'Start a new AI conversation'],
        ['command' => 'support', 'description' => 'Report a problem or send feedback'],
        ['command' => 'help', 'description' => 'All commands and tips'],
        ['command' => 'unlink', 'description' => 'Unlink Telegram; the account stays on the website'],
    ];

    /** Callback-data suffix tying a confirm button to the preview it was sent with. */
    public const NONCE_PATTERN = '[a-f0-9]{8}';

    public const RECENT_LIMIT = 5;

    public const NOTE_LIST_MAX = 28;

    /** ↩️ Undo on a freshly saved card works this long. */
    public const UNDO_SECONDS = 300;

    private const TAGS_PER_PAGE = 8;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly ListTagsAction $listTags,
        private readonly QuickAddParser $quickAdd,
        private readonly MatchTagTitlesAction $matchTags,
    ) {}

    /**
     * /start reply: a headline (account created, linked, already linked) and the same short blocks.
     */
    public function sendWelcome(int|string $chatId, string $headline, bool $newAccount = false): void
    {
        $this->client->sendMessage(
            $chatId,
            $headline . "\n\n" . $this->welcomeBlocks($newAccount),
            $this->menuKeyboard(),
            self::PARSE_HTML,
        );
    }

    /**
     * Telegram HTML; English UI copy.
     */
    public function welcomeBlocks(bool $newAccount = false): string
    {
        return "<b>➕ Add</b>\n"
            . "Just write or say it: <i>350 groceries yesterday</i>\n"
            . "Send a receipt photo or PDF\n"
            . "Or tap ➕ Add for the keypad\n\n"
            . "<b>💬 Ask</b>\n"
            . "<i>How much did I spend on food this month?</i>\n"
            . "<i>Set a 5000 limit on cafes</i>\n\n"
            . ($newAccount
                ? "<b>🌐 Website</b>\n/website gives a one-time code to log in on the web\n\n"
                : '')
            . "<b>⚡ AI limit</b>\n"
            . "Text, voice and receipts use your daily AI limit. ➕ Add is free\n\n"
            . 'Menu below, /help for all commands';
    }

    public function helpText(): string
    {
        return "<b>Add &amp; view</b>\n"
            . "/add — keypad, no AI needed\n"
            . "/recent — latest transactions\n"
            . "/month — this month at a glance\n"
            . "/tags — expenses and income by tag\n\n"
            . "<b>Plan</b>\n"
            . "/budgets — limits and savings goals\n"
            . "/recurring — repeating payments\n\n"
            . "<b>Settings &amp; account</b>\n"
            . "/notifications — alerts, reminders, pinned month\n"
            . "/website — code to log in on the web\n"
            . "/reset — new AI conversation\n"
            . "/support — report a problem or idea\n"
            . "/unlink — unlink Telegram\n\n"
            . "<b>Tips</b>\n"
            . "Write or say it: <i>350 groceries yesterday</i>, <i>+15000 salary</i>\n"
            . "Send a receipt photo or PDF, a caption helps\n"
            . "Ask anything: <i>where did my money go this week?</i>\n"
            . "Reply to a bot message to ask about it\n"
            . "Fix a new entry with ✏️ Edit, or ↩️ Undo within 5 minutes\n"
            . '<blockquote expandable>Text, voice notes and receipts share a daily AI limit; Settings in the Web UI shows what is left. '
            . '➕ Add and the menu buttons never use it. '
            . 'The assistant remembers the last hour, shared with the web chat; /reset clears it. '
            . 'Live widgets work in a private chat with the bot; in groups, commands answer with text. '
            . 'Limit alerts come at 80% and 100%, payment reminders the evening before; /notifications has the switches</blockquote>';
    }

    public function sendHelp(int|string $chatId): void
    {
        $this->client->sendMessage($chatId, $this->helpText(), $this->menuKeyboard(), self::PARSE_HTML);
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
                [self::MENU_ADD, self::MENU_RECENT],
                [self::MENU_MONTH, self::MENU_BUDGETS],
                [self::MENU_TAGS, self::MENU_RECURRING],
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

    /**
     * 10-cell bar, filled cells rounded down (96% never looks full). Callers append the percent.
     */
    public function progressBar(float $ratio, int $width = 10): string
    {
        $filled = (int) floor(round(max(0.0, min(1.0, $ratio)) * $width, 6));

        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
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

    /**
     * "🏷 Food" for a tag without its own emoji; "💼 Salary · 🅱️ PUMB" as is, so the line doesn't
     * start with two emoji in a row.
     */
    public static function tagLine(string $label): string
    {
        return self::startsWithEmoji(strip_tags($label)) ? $label : '🏷 ' . $label;
    }

    public static function startsWithEmoji(string $text): bool
    {
        return preg_match('/^[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{2190}-\x{21FF}\x{3030}\x{303D}\x{3297}\x{3299}\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}]/u', ltrim($text)) === 1;
    }

    /**
     * Cards use DD.MM.YYYY everywhere in Telegram (widgets, editor, text cards), whatever the web
     * app's date display setting: a year-less "11 Nov" is ambiguous for older entries.
     */
    public static function cardDate(\Carbon\CarbonInterface|string $date): string
    {
        return ($date instanceof \Carbon\CarbonInterface ? $date : \Illuminate\Support\Carbon::parse($date))->format('d.m.Y');
    }

    /**
     * The one card layout for a transaction everywhere (saved, updated, confirm, deleted):
     * headline, bold signed amount, 📅 date, 📝 note, 📎 files, 🏷 tags. The sign carries the
     * direction, so there is no 📉/📈.
     */
    public function formatTransactionCard(User $user, Transaction $transaction, string $headline = '✅ Saved'): string
    {
        $money = $this->escapeHtml($this->signedMoney($user, (float) $transaction->amount));
        $date = $this->escapeHtml(self::cardDate($transaction->at));

        $lines = [
            $this->escapeHtml($headline),
            '',
            "<b>{$money}</b>",
            "📅 {$date}",
        ];

        if ($transaction->note) {
            $lines[] = '📝 ' . $this->escapeHtml($transaction->note);
        }

        if ($transaction->relationLoaded('attachments') && $transaction->attachments->isNotEmpty()) {
            $count = $transaction->attachments->count();
            $lines[] = '📎 ' . $count . ' file' . ($count === 1 ? '' : 's');
        }

        $lines[] = self::tagLine($this->formatTagListHtml($transaction));

        return implode("\n", $lines);
    }

    public function formatTransactionListItem(User $user, Transaction $transaction, int $index): string
    {
        $money = $this->escapeHtml(UserFormatter::formatMoney($user, $transaction->amount));
        $note = $transaction->note
            ? $this->escapeHtml($this->truncateNote($transaction->note))
            : '—';
        $date = $this->escapeHtml(self::cardDate($transaction->at));
        // All tags, like the card ("💼 Salary · 🅱️ PUMB"); past three it says "+N".
        $labels = $transaction->tags->map(fn ($tag) => $this->escapeHtml(trim($tag->emoji . ' ' . $tag->title)))->values();
        $tagLabel = $labels->isEmpty()
            ? 'Untagged'
            : $labels->take(3)->implode(' · ') . ($labels->count() > 3 ? ' +' . ($labels->count() - 3) : '');

        $meta = "📅 {$date} · " . self::tagLine($tagLabel);

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
            self::cardDate($transaction->at),
        );
    }

    /**
     * $remember: also note it in the assistant memory ("Saved: …", "Receipt saved: …"); off when
     * the card only re-shows an existing transaction.
     */
    public function sendSavedTransaction(
        int|string $chatId,
        User $user,
        Transaction $transaction,
        string $headline = '✅ Saved',
        bool $remember = true,
        ?int $editMessageId = null,
        bool $undo = false,
    ): void {
        $text = $this->formatTransactionCard($user, $transaction, $headline);
        $keyboard = $this->postSaveKeyboard($transaction, $chatId, $undo);

        if ($editMessageId !== null) {
            // A confirm card or the "Reading receipt…" line turns into the result in place.
            $this->client->editMessageText($chatId, $editMessageId, $text, $keyboard, self::PARSE_HTML);
        } else {
            $this->client->sendMessage($chatId, $text, $keyboard, self::PARSE_HTML);
        }

        if ($remember) {
            $label = trim(preg_replace('/^[^\p{L}]+/u', '', $headline) ?? $headline);
            $label = match (true) {
                str_contains($label, 'receipt') => 'Receipt saved',
                $label === 'Saved without file' => 'Receipt saved (file not attached)',
                default => $label,
            };
            app(TelegramHistory::class)->outcome($chatId, $user, $label . ': ' . TelegramHistory::transactionLine($user, $transaction));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function postSaveKeyboard(Transaction $transaction, int|string|null $chatId = null, bool $undo = false): array
    {
        $id = $transaction->id;
        $undo = $undo && $this->canUndo($transaction);
        $undoButton = ['text' => '↩️ Undo', 'callback_data' => "undo:{$id}"];
        $delete = ['text' => '🗑 Delete', 'callback_data' => "delask:{$id}"];

        if ($chatId === null || self::isPrivateChat($chatId)) {
            // Private chat: one ✏️ Edit opens the widget editor (amount, date, tags, note).
            $row = [['text' => '✏️ Edit', 'callback_data' => "edit:{$id}"], $delete];

            return ['inline_keyboard' => [$undo ? [$undoButton, ...$row] : $row]];
        }

        // Groups have no widgets: tag picker and a typed amount, as before.
        $rows = [[
            ['text' => '🏷 Tags', 'callback_data' => "retag:{$id}"],
            ['text' => '✏️ Amount', 'callback_data' => "editamt:{$id}"],
            $delete,
        ]];

        if ($undo) {
            array_unshift($rows, [$undoButton]);
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * Undo is for "I didn't mean that": a few minutes after the entry was created.
     */
    public function canUndo(Transaction $transaction): bool
    {
        return $transaction->created_at !== null
            && $transaction->created_at->gt(now()->subSeconds(self::UNDO_SECONDS));
    }

    /**
     * Headline of a card message, kept when its keyboard re-renders the card (tag picker, delete
     * cancelled). Unknown first lines (a Monobank line) become "Transaction".
     */
    public static function cardHeadline(?string $messageText, string $fallback = 'Transaction'): string
    {
        $first = trim(strtok((string) $messageText, "\n") ?: '');

        return preg_match('/^(✅ |🔁 |✏️ |Transaction$)/u', $first) ? $first : $fallback;
    }

    public static function isPrivateChat(int|string $chatId): bool
    {
        return (int) $chatId > 0;
    }

    public function signedMoney(User $user, float $amount): string
    {
        $formatted = UserFormatter::formatMoney($user, $amount);

        return $amount > 0 && !str_starts_with($formatted, '+') ? '+' . $formatted : $formatted;
    }

    /**
     * Two short lines and a ➕ Add button (free, no AI) when today's AI assists are used up.
     */
    public function sendQuotaExhausted(int|string $chatId, User $user, TelegramAiQuota $quota): void
    {
        $limit = $quota->dailyLimit($user);

        if (!$quota->enabledGlobally() || $limit <= 0) {
            $text = "AI is turned off right now\n➕ Add and the menu still work";
        } else {
            $text = "All {$limit} AI assists for today are used\nThey're back at midnight. ➕ Add works without AI";
        }

        $this->client->sendMessage($chatId, $text, $this->addButtonKeyboard());
    }

    /**
     * @return array<string, mixed>
     */
    public function addButtonKeyboard(): array
    {
        return ['inline_keyboard' => [[['text' => '➕ Add', 'callback_data' => 'menu:add']]]];
    }

    /**
     * Before → after for an agent's update, the card for a delete or a staged create.
     * Changed fields show "<s>old</s> → new"; unchanged ones as on the saved card.
     *
     * @param array<string, mixed> $payload
     */
    public function formatAgentPreviewCard(User $user, string $action, array $payload, ?Transaction $before): string
    {
        if ($action === 'delete_transaction' && $before) {
            return $this->formatTransactionCard($user, $before, '🗑 Delete this transaction?');
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $at = (string) ($payload['at'] ?? $user->todayDateString());
        $note = isset($payload['note']) && is_string($payload['note']) && $payload['note'] !== '' ? $payload['note'] : null;
        $tagIds = array_map('intval', is_array($payload['tag_ids'] ?? null) ? $payload['tag_ids'] : []);
        $tagLabel = fn (array $ids): string => $ids === []
            ? 'No tags'
            : $user->tags()->whereIn('id', $ids)->orderBy('title')->get(['emoji', 'title'])
                ->map(fn (Tag $tag) => $this->escapeHtml(trim($tag->emoji . ' ' . $tag->title)))->implode(' · ');
        $money = fn (float $v): string => $this->escapeHtml($this->signedMoney($user, $v));
        $date = fn (string $d): string => $this->escapeHtml(self::cardDate($d));
        $diff = fn (string $old, string $new): string => $old === $new ? $new : "<s>{$old}</s> → {$new}";

        if ($action !== 'update_transaction' || !$before) {
            $lines = ['➕ Save this transaction?', '', '<b>' . $money($amount) . '</b>', '📅 ' . $date($at)];

            if ($note !== null) {
                $lines[] = '📝 ' . $this->escapeHtml($note);
            }
            $lines[] = self::tagLine($tagLabel($tagIds));

            return implode("\n", $lines);
        }

        $oldTags = $before->tags->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $newTags = collect($tagIds)->sort()->values()->all();
        $oldNote = $before->note ? $this->escapeHtml($before->note) : '—';
        $newNote = $note !== null ? $this->escapeHtml($note) : '—';

        $lines = [
            '✏️ Change this transaction?',
            '',
            '<b>' . $diff($money((float) $before->amount), $money($amount)) . '</b>',
            '📅 ' . $diff($date($before->at->toDateString()), $date($at)),
        ];

        if ($oldNote !== '—' || $newNote !== '—') {
            $lines[] = '📝 ' . $diff($oldNote, $newNote);
        }
        $lines[] = self::tagLine($oldTags === $newTags ? $tagLabel($newTags) : $diff($tagLabel($oldTags), $tagLabel($newTags)));

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteConfirmKeyboard(int $transactionId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🗑 Delete', 'callback_data' => "delyes:{$transactionId}"],
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
                ['text' => '↩️ Cancel', 'callback_data' => 'mediacancel'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptDraftKeyboard(string $nonce, int|string|null $chatId = null): array
    {
        // Private chat: ✏️ Edit opens the widget editor; groups type a new amount.
        $private = $chatId === null || self::isPrivateChat($chatId);

        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => "draft:ok:{$nonce}"],
                $private
                    ? ['text' => '✏️ Edit', 'callback_data' => "draft:open:{$nonce}"]
                    : ['text' => '✏️ Amount', 'callback_data' => "draft:edit:{$nonce}"],
                ['text' => '↩️ Cancel', 'callback_data' => "draft:cancel:{$nonce}"],
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
            ], [
                ['text' => '📋 Transaction list', 'callback_data' => 'album:list:' . $groupId],
            ]],
        ];
    }

    /**
     * Under "these tags don't exist yet" for an import; a typed reply works too.
     *
     * @return array<string, mixed>
     */
    public function importTagsKeyboard(string $nonce): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🆕 Create them', 'callback_data' => "imp:create:{$nonce}"],
                ['text' => 'Without tags', 'callback_data' => "imp:none:{$nonce}"],
            ], [
                ['text' => '↩️ Cancel', 'callback_data' => "imp:cancel:{$nonce}"],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nlCreateTagsKeyboard(string $nonce): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => "nl:tags:ok:{$nonce}"],
                ['text' => '↩️ Cancel', 'callback_data' => "nl:tags:cancel:{$nonce}"],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nlRenameConfirmKeyboard(string $nonce): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => "nl:rename:ok:{$nonce}"],
                ['text' => '↩️ Cancel', 'callback_data' => "nl:rename:cancel:{$nonce}"],
            ]],
        ];
    }

    /**
     * Confirm keyboard for agent-staged mutations (transactions, budgets, …).
     *
     * @return array<string, mixed>
     */
    public function nlAgentConfirmKeyboard(string $nonce, bool $delete = false): array
    {
        return [
            'inline_keyboard' => [[
                $delete
                    ? ['text' => '🗑 Delete', 'callback_data' => "nl:agent:ok:{$nonce}"]
                    : ['text' => '✅ Confirm', 'callback_data' => "nl:agent:ok:{$nonce}"],
                ['text' => '↩️ Cancel', 'callback_data' => "nl:agent:cancel:{$nonce}"],
            ]],
        ];
    }

    public function newNonce(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * First caller wins: a double tap or a webhook retry on the same button
     * must not apply the change twice.
     */
    public function claimNonce(string $nonce): bool
    {
        return Cache::add('telegram_confirm_claimed:' . $nonce, true, now()->addHour());
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
    public function sendReceiptDraft(int|string $chatId, User $user, array $draft, ?int $editMessageId = null): ?int
    {
        $text = $this->formatReceiptDraftCard($user, $draft);
        $keyboard = $this->receiptDraftKeyboard((string) ($draft['nonce'] ?? ''), $chatId);

        if ($editMessageId !== null) {
            $this->client->editMessageText($chatId, $editMessageId, $text, $keyboard, self::PARSE_HTML);

            return $editMessageId;
        }

        $result = $this->client->sendMessageResult($chatId, $text, $keyboard, self::PARSE_HTML);
        $id = $result['result']['message_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function formatReceiptDraftCard(User $user, array $draft): string
    {
        $amount = $draft['amount'] ?? null;
        $amount = is_numeric($amount) ? (float) $amount : null;
        $money = $amount !== null
            ? $this->escapeHtml($this->signedMoney($user, $amount))
            : '?';
        $at = (string) ($draft['at'] ?? $user->todayDateString());
        $date = $this->escapeHtml(self::cardDate($at));

        $lines = [
            $this->escapeHtml('🧾 Receipt draft'),
            '',
            "<b>{$money}</b>",
            "📅 {$date}",
        ];

        $note = $draft['note'] ?? null;

        if (is_string($note) && $note !== '') {
            $lines[] = '📝 ' . $this->escapeHtml($note);
        }

        $titles = $draft['suggested_tag_titles'] ?? [];

        if (is_array($draft['tag_ids'] ?? null)) {
            // Picked in the editor: exact ids win over the AI's title guesses.
            $tags = $user->tags()->whereIn('id', $draft['tag_ids'])->orderBy('title')->get(['emoji', 'title']);
            $lines[] = self::tagLine($tags->isEmpty() ? 'No tags' : $tags->map(fn (Tag $tag) => $this->escapeHtml(trim($tag->emoji . ' ' . $tag->title)))->implode(' · '));
        } elseif (is_array($titles) && $titles !== []) {
            $matched = $this->matchSuggestedTagTitles($user, $titles);

            if ($matched !== []) {
                $label = implode(', ', array_map(
                    fn (Tag $tag) => $this->escapeHtml(trim($tag->emoji . ' ' . $tag->title)),
                    $matched,
                ));
                $lines[] = self::tagLine($label);
            } else {
                $hint = implode(', ', array_map(fn ($t) => $this->escapeHtml((string) $t), $titles));
                $lines[] = '🏷 suggested: ' . $hint;
            }
        } else {
            $lines[] = '🏷 No tags';
        }

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
        return $this->matchTags->execute($user, $titles);
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
        return $this->quickAdd->parseDate($raw);
    }

    /**
     * Only for a reply to the bot's own "send the amount" question (a receipt waiting for its
     * amount when AI can't read it). Free text never goes through this: it goes to the AI.
     *
     * @return null|array{at: string, amount: float, note: ?string}|array{error: string}
     */
    public function parseAmountReply(string $text, ?User $user = null): ?array
    {
        return $this->quickAdd->parseQuickAdd($text, $user);
    }
}
