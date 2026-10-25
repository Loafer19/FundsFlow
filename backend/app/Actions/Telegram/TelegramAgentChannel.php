<?php

namespace App\Actions\Telegram;

use App\Actions\Agent\AgentChannel;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Models\User;
use App\Support\TelegramAiQuota;

/**
 * Telegram transport for RunAgentAction: tools send Telegram cards/keyboards
 * themselves, the final answer is one chat message.
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
    ) {}

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
- Mutations (create/update/delete money objects or tags) MUST go through create_*/update_*/delete_*/rename_tags tools which only stage a Confirm UI. Never claim a write succeeded before confirmation.
- If you need a missing detail, call ask_user (optional English suggestions). Then stop.
- Quick-add like "-350 groceries" is handled outside you; focus on questions, analysis, lists, and proposed mutations.
- Cap: tools already limit list sizes. Do not dump huge raw JSON to the user; summarize.
PROMPT;
    }

    public function historyMessages(): array
    {
        // Telegram keeps context via the session summary + last_list only.
        return [];
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
        return $this->tools->execute($user, $this->chatId, $this->identity, $name, $arguments);
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

    public function streams(): bool
    {
        return false;
    }

    public function onDelta(string $text): void {}

    public function onToolCall(string $name, array $arguments): void {}

    public function onAnswer(string $text): void
    {
        $this->client->sendMessage($this->chatId, mb_substr($text, 0, 4000));
        $this->session->setSummary($this->identity, $this->chatId, mb_substr($text, 0, 500));
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
}
