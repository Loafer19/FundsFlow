<?php

namespace App\Actions\Telegram;

use App\Actions\Agent\AgentChannel;
use App\Actions\Agent\AgentHistory;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Models\User;
use App\Support\TelegramAiQuota;

/**
 * Telegram transport for RunAgentAction: tools send Telegram cards/keyboards
 * themselves, the final answer is a formatted (HTML) reply to the user's message.
 *
 * In a private chat the conversation memory is shared with the web assistant
 * (AgentHistory): $history holds the prior turns, and this channel records the
 * answer, questions, proposals and shown cards. Group chats pass no history.
 */
final class TelegramAgentChannel implements AgentChannel
{
    /** What still works in the bot without AI (quota exhausted). */
    public const WITHOUT_AI = 'Until then, quick-add like -350 groceries and the menu buttons still work.';

    public function __construct(
        private readonly TelegramAiQuota $quota,
        private readonly TelegramClient $client,
        private readonly TelegramChatSession $session,
        private readonly TelegramAgentToolExecutor $tools,
        private readonly int|string $chatId,
        private readonly int|string $identity,
        private readonly ?AgentHistory $history = null,
        private readonly ?int $userId = null,
        /** @var list<array{role: 'user'|'assistant', content: string}> */
        private readonly array $priorTurns = [],
        private readonly ?int $replyTo = null,
    ) {
        $this->draftId = random_int(1, 2_000_000_000);
    }

    private float $lastTyping = 0.0;

    private int $draftId;

    private string $draft = '';

    private float $lastDraft = 0.0;

    private bool $draftsFailed = false;

    /**
     * "typing…" in the chat header. Telegram shows it for ~5 s, so it is refreshed
     * at most every 4 s: at the start and before each tool call.
     */
    public function typing(bool $force = false): void
    {
        $now = microtime(true);
        if (!$force && $now - $this->lastTyping < 4.0) {
            return;
        }
        $this->lastTyping = $now;
        $this->client->sendChatAction($this->chatId);
    }

    public function systemPrompt(User $user): string
    {
        $session = $this->session->get($this->identity, $this->chatId);
        $today = $user->todayDateString();
        $tz = $user->timezone();
        $domain = $session['domain'] ?? null;
        $summary = (string) ($session['last_bot_summary'] ?? '');
        $lastList = $session['last_list'] ?? [];
        $lastListJson = json_encode(array_slice(is_array($lastList) ? $lastList : [], 0, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lastListJson = is_string($lastListJson) ? $lastListJson : '[]';

        return <<<PROMPT
You are the FundsFlow personal finance assistant inside Telegram.
Today (user timezone {$tz}) is {$today}.
Session domain: {$domain}
Last bot summary: {$summary}
last_list (ids are authoritative; never invent ids): {$lastListJson}

Rules:
- Use tools for ANY numbers, balances, lists, budgets, or ids. Never invent amounts, tag ids, transaction ids, or budget figures.
- Prefer concise replies. Match the user's language for narrative advice (Ukrainian if they wrote Ukrainian; otherwise English). Keep button labels English via tools.
- Bot UI chrome (menus, Confirm/Cancel, slash help) is English — use the show_* / ask_user tools rather than inventing Telegram callback_data or raw keyboard JSON.
- For "show recent/budgets/tags/month/menu/help/web" prefer the matching show_* tool so cards stay consistent.
- For any request to see a chart/graph/diagram/visual (e.g. "покажи витрати по тегах за місяць", "chart of spending", "графік доходів і витрат", "balance trend"), call send_chart once with the best type/period (spending by tag -> expenses_by_tag; income vs expenses -> income_vs_expenses; balance over time -> balance_trend; "за місяць"/"this month" -> this_month unless they say last month). It sends the image itself; then stop.
- For analysis questions (e.g. what do you think about my finances), call period_summary, list_budgets, list_recent_transactions (and more if needed), then answer with real figures.
- Budgets: for "how is my budget / скільки лишилось / can I still spend" call budget_status (spent, left, days left, per-day allowance, projection) and answer with those figures. To change, pause, resume or delete a budget use update_budget / pause_budget / resume_budget / delete_budget (ids from list_budgets or budget_status; include_paused=true to find paused ones).
- Spending limits run on calendar weeks/months/years by default (create_budget without start_date: spending since the start of the current period counts). Pass start_date only when the user names a start day ("monthly from the 15th" = start_date on the 15th). Editing amount, length or tags applies to the whole current period: spent still counts from its start. Goal deadlines must be today or later.
- Savings goals are budgets with type goal: a target amount reached through transactions with the goal's savings tag(s) since the goal started (transfers into savings logged as expenses add, withdrawals subtract; plus an optional start_amount already saved). For "how is my goal / when will I reach it" call budget_status (saved, remaining, deadline, required per month/week, pace, projected date). To create one use create_budget with type goal (amount = target, tag_ids = savings tag(s), optional start_date if the user started saving earlier (today or a past day; progress counts from it), target_date and start_amount); if the user has no savings tag, suggest creating one (create_tags) first. Edit, pause, resume, delete goals with the same budget tools.
- Recurring transactions: create_recurring / update_recurring / pause_recurring / resume_recurring / delete_recurring (ids from list_recurring). For "what is coming up / upcoming charges / що спишеться" call upcoming_recurring (default 7 days).
- Comparisons ("compare this quarter with last", "чи я витратив більше ніж минулого місяця"): call compare_periods and report totals and the biggest per-tag changes; if the current period is not finished, say so and use previous_to_date for a fair comparison. For a visual comparison use send_chart with compare=true.
- For "send me a report / PDF / звіт" call send_report with the period (and tag_ids if they named tags); it sends the PDF itself; then stop.
- Mutations (create/update/delete money objects or tags) MUST go through create_*/update_*/delete_*/rename_tags tools which only stage a Confirm UI. Never claim a write succeeded before confirmation.
- If you need a missing detail, call ask_user (optional English suggestions). Then stop.
- Quick-add like "-350 groceries" is handled outside you; focus on questions, analysis, lists, and proposed mutations.
- Cap: tools already limit list sizes. Do not dump huge raw JSON to the user; summarize.
- Formatting: light Markdown is rendered — **bold**, *italic*, `code`, short "- " bullet lists, [links](https://…). No tables, no # headings, no HTML. Keep answers short; never write more than ~3000 characters.
- Earlier turns tagged [via web] happened in the FundsFlow web assistant; the user may continue them here. A "Proposed change … via web" can only be confirmed in the web chat: to do it here, propose it again with the tool.
- "(Replying to your earlier message: …)" quotes the bot message the user replied to; treat it as what "this"/"it" refers to.
PROMPT;
    }

    public function historyMessages(): array
    {
        // Shared with the web assistant in private chats; group chats keep context via
        // the session summary + last_list only.
        return $this->priorTurns;
    }

    public function toolDefinitions(): array
    {
        return $this->tools->definitions();
    }

    public function stagingTools(): array
    {
        return TelegramAgentToolExecutor::STAGING_TOOLS;
    }

    public function executeTool(User $user, string $name, array $arguments): array
    {
        $executed = $this->tools->execute($user, $this->chatId, $this->identity, $name, $arguments);
        $this->record($executed);

        return $executed;
    }

    public function controlNote(string $control): ?string
    {
        return match ($control) {
            'ui_sent' => 'Standard Telegram UI was already sent to the user. Do not repeat the full list; you may stop.',
            'ask_user' => 'Clarifying question was sent. Stop and wait for the user reply.',
            'confirm_staged' => 'A Confirm/Cancel preview was sent. Stop and wait; do not claim the change is saved.',
            default => null,
        };
    }

    /**
     * Draft streaming (Bot API 9.5 sendMessageDraft): on by default (TELEGRAM_STREAM_DRAFTS),
     * private chats only. Group chats (or streaming off) see "typing…" and then the whole answer.
     */
    public function streams(): bool
    {
        return (bool) config('telegram.stream_drafts') && $this->isPrivate();
    }

    public function onDelta(string $text): void
    {
        if ($this->draftsFailed) {
            return;
        }
        $this->draft .= $text;

        $now = microtime(true);
        if ($now - $this->lastDraft < 1.0) {
            return;
        }
        $this->lastDraft = $now;

        // Raw text while streaming: half-written Markdown can't be turned into valid HTML.
        if (!$this->client->sendMessageDraft($this->chatId, $this->draftId, mb_substr($this->draft, 0, 4000))) {
            $this->draftsFailed = true;
        }
    }

    public function onToolCall(string $name, array $arguments): void
    {
        // Text streamed before a tool call is a preamble, not the answer.
        $this->draft = '';
        $this->typing();
    }

    public function onAnswer(string $text): void
    {
        $this->client->sendFormatted($this->chatId, $text, replyTo: $this->replyTo);
        $this->session->setSummary($this->identity, $this->chatId, mb_substr($text, 0, 500));
        $this->remember($text);
    }

    public function onMaxRounds(): void
    {
        $this->client->sendMessage(
            $this->chatId,
            'I gathered some data but hit the step limit. Try a narrower question, or use the menu buttons.',
        );
        $this->session->setSummary($this->identity, $this->chatId, 'Agent hit max rounds.');
    }

    public function quotaExhaustedMessage(User $user): string
    {
        return $this->quota->exhaustedMessage($user, self::WITHOUT_AI);
    }

    private function isPrivate(): bool
    {
        return (string) $this->chatId === (string) $this->identity;
    }

    /**
     * @param array{kind?: string, ref?: string|null} $meta
     */
    private function remember(string $text, array $meta = []): void
    {
        if ($this->history !== null && $this->userId !== null) {
            $this->history->add($this->userId, AgentHistory::TELEGRAM, 'assistant', $text, $meta);
        }
    }

    /**
     * What a stopping tool showed, as text for the shared memory.
     *
     * @param array{ok: bool, control: ?string, result: array<string, mixed>} $executed
     */
    private function record(array $executed): void
    {
        if ($this->history === null || $this->userId === null || !$executed['ok']) {
            return;
        }
        $result = $executed['result'];

        switch ($executed['control']) {
            case 'ask_user':
                $this->remember((string) ($result['question'] ?? ''), ['kind' => 'ask']);
                break;

            case 'confirm_staged':
                $pending = $this->session->get($this->identity, $this->chatId)['pending'] ?? null;
                if (($result['staged'] ?? false) !== true || !is_array($pending) || !is_string($pending['nonce'] ?? null)) {
                    break;
                }
                $preview = match ($pending['type'] ?? null) {
                    'create_tags' => 'Create tags: ' . implode(', ', array_map('strval', (array) ($pending['titles'] ?? []))),
                    'rename_tags' => "Rename tags:\n" . implode("\n", array_map(
                        static fn ($p): string => is_array($p) ? ($p['before'] ?? '?') . ' → ' . ($p['after'] ?? '?') : '',
                        (array) ($pending['proposals'] ?? []),
                    )),
                    default => (string) ($pending['preview'] ?? ''),
                };
                $this->history->addProposal($this->userId, AgentHistory::TELEGRAM, $preview, $pending['nonce']);
                break;

            case 'ui_sent':
                $ui = (string) ($result['ui'] ?? 'card');
                $summary = is_string($result['summary'] ?? null) ? ': ' . $result['summary'] : '';
                if (($result['sent'] ?? true) !== false) {
                    $this->remember("Shown in Telegram: {$ui}{$summary}");
                }
                break;
        }
    }
}
