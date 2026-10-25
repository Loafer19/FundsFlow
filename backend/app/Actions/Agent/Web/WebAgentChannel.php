<?php

namespace App\Actions\Agent\Web;

use App\Actions\Agent\AgentChannel;
use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Charts\BuildUserChartAction;
use App\Actions\Telegram\TelegramAgentToolExecutor;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Web chat drawer transport for RunAgentAction. Results are pushed to the
 * browser as SSE events (via $emit) carrying structured transcript items
 * instead of Telegram messages; charts are data for the Vue chart components.
 */
final class WebAgentChannel implements AgentChannel
{
    private const TOOL_LABELS = [
        'get_me' => 'Checking your settings',
        'list_tags' => 'Reading tags',
        'list_recent_transactions' => 'Reading recent transactions',
        'list_transactions' => 'Searching transactions',
        'list_budgets' => 'Reading budgets',
        'list_recurring' => 'Reading recurring rules',
        'period_summary' => 'Summarizing the month',
        'show_recent' => 'Preparing recent transactions',
        'show_budgets' => 'Preparing budgets',
        'show_tags' => 'Preparing tags',
        'show_recurring' => 'Preparing recurring rules',
        'show_month' => 'Preparing month summary',
        'send_chart' => 'Building chart',
        'ask_user' => 'Asking a question',
        'create_tags' => 'Preparing tags to create',
        'rename_tags' => 'Preparing tag renames',
        'create_transaction' => 'Preparing transaction',
        'update_transaction' => 'Preparing update',
        'delete_transaction' => 'Preparing delete',
        'create_budget' => 'Preparing budget',
    ];

    /** What still works in the web app without AI (quota exhausted). */
    public const WITHOUT_AI = 'Until then, you can still add transactions with the + button and explore your tabs and analytics.';

    /**
     * @param Closure(string, array<string, mixed>): void $emit
     * @param list<array{role: 'user'|'assistant', content: string}> $history
     */
    public function __construct(
        private readonly TelegramAiQuota $quota,
        private readonly AgentToolExecutor $tools,
        private readonly BuildUserChartAction $buildChart,
        private readonly WebAgentSession $session,
        private readonly Closure $emit,
        private readonly array $history = [],
    ) {}

    public function systemPrompt(User $user): string
    {
        $session = $this->session->get();
        $today = $user->todayDateString();
        $tz = $user->timezone();
        $domain = $session['domain'] ?? null;
        $summary = (string) ($session['last_bot_summary'] ?? '');
        $lastListJson = json_encode(array_slice($session['last_list'], 0, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lastListJson = is_string($lastListJson) ? $lastListJson : '[]';

        return <<<PROMPT
You are the FundsFlow personal finance assistant in the chat panel of the FundsFlow web app.
Today (user timezone {$tz}) is {$today}.
Session domain: {$domain}
Last bot summary: {$summary}
last_list (ids are authoritative; never invent ids): {$lastListJson}

Rules:
- Use tools for ANY numbers, balances, lists, budgets, or ids. Never invent amounts, tag ids, transaction ids, or budget figures.
- Prefer concise replies. Match the user's language (Ukrainian if they wrote Ukrainian; otherwise English). You may use light Markdown (bold, short bullet lists, inline code); no tables, images or HTML.
- For "show recent/budgets/tags/recurring/month" prefer the matching show_* tool: it renders a card in the chat. Then stop.
- For any request to see a chart/graph/diagram/visual (e.g. "покажи витрати по тегах за місяць", "chart of spending", "графік доходів і витрат", "balance trend"), call send_chart once with the best type/period (spending by tag -> expenses_by_tag; income vs expenses -> income_vs_expenses; balance over time -> balance_trend; "за місяць"/"this month" -> this_month unless they say last month). It renders an interactive chart in the chat; then stop.
- For analysis questions (e.g. what do you think about my finances), call period_summary, list_budgets, list_recent_transactions (and more if needed), then answer with real figures.
- Mutations (create/update/delete transactions, budgets or tags) MUST go through create_*/update_*/delete_*/rename_tags tools which only stage a Confirm/Cancel card. Never claim a write succeeded before confirmation.
- If you need a missing detail, call ask_user (optional short English suggestions shown as buttons). Then stop.
- Cap: tools already limit list sizes. Do not dump huge raw JSON to the user; summarize.
PROMPT;
    }

    public function historyMessages(): array
    {
        return $this->history;
    }

    public function toolDefinitions(): array
    {
        $fn = AgentToolExecutor::fn(...);
        $noArgs = ['type' => 'object', 'properties' => (object) []];

        return [
            $this->tools->definition('get_me'),
            $this->tools->definition('list_tags'),
            $fn('list_recent_transactions', 'Return the newest transactions (newest first) as data for your answer. Use show_recent instead to show the user a card.', [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => AgentToolExecutor::MAX_RECENT, 'description' => 'Max rows (default 10, max 20)'],
                ],
            ]),
            $this->tools->definition('list_transactions'),
            $this->tools->definition('list_budgets'),
            $this->tools->definition('list_recurring'),
            $this->tools->definition('period_summary'),
            $fn('show_recent', 'Show the Recent transactions card in the chat (10 newest), then stop.', $noArgs),
            $fn('show_budgets', 'Show the Budgets card (limit, spent, progress) in the chat, then stop.', $noArgs),
            $fn('show_tags', 'Show the Tags card in the chat and update session last_list, then stop.', $noArgs),
            $fn('show_recurring', 'Show the Recurring rules card in the chat, then stop.', $noArgs),
            $fn('show_month', 'Show the this-month summary card (income, expenses, net, top tags), then stop.', $noArgs),
            $fn('send_chart', 'Render an interactive chart from the user\'s transactions in the chat with a short caption, then stop. Use for any request to show/draw a chart, graph, diagram or visual breakdown (e.g. spending by tag, income vs expenses, balance trend).', TelegramAgentToolExecutor::chartParameters()),
            $this->tools->definition('ask_user'),
            $this->tools->definition('create_tags'),
            $this->tools->definition('rename_tags'),
            $this->tools->definition('create_transaction'),
            $this->tools->definition('update_transaction'),
            $this->tools->definition('delete_transaction'),
            $this->tools->definition('create_budget'),
        ];
    }

    public function stagingTools(): array
    {
        return AgentToolExecutor::STAGING_TOOLS;
    }

    public function executeTool(User $user, string $name, array $arguments): array
    {
        $tools = $this->tools;
        $session = $this->session;

        try {
            return match ($name) {
                'get_me' => AgentToolExecutor::ok($tools->getMe($user)),
                'list_tags' => AgentToolExecutor::ok($tools->listTags($user, $session)),
                'list_recent_transactions' => AgentToolExecutor::ok($tools->listRecent($user, $session, $arguments)),
                'list_transactions' => AgentToolExecutor::ok($tools->listTransactions($user, $session, $arguments)),
                'list_budgets' => AgentToolExecutor::ok($tools->listBudgets($user, $session)),
                'list_recurring' => AgentToolExecutor::ok($tools->listRecurring($user)),
                'period_summary' => AgentToolExecutor::ok($tools->periodSummary($user, $arguments)),
                'show_recent' => $this->show('recent', 'transactions', 'Recent transactions', $tools->listRecent($user, $session, ['limit' => 10])),
                'show_budgets' => $this->show('budgets', 'budgets', 'Budgets', $tools->listBudgets($user, $session)),
                'show_tags' => $this->show('tags', 'tags', 'Tags', $tools->listTags($user, $session)),
                'show_recurring' => $this->show('recurring', 'recurring', 'Recurring rules', $tools->listRecurring($user)),
                'show_month' => $this->show('month', 'month', 'This month', $tools->periodSummary($user, [])),
                'send_chart' => $this->chart($user, $arguments),
                'ask_user' => $this->ask($arguments),
                'create_tags' => $this->stageCreateTags($user, $arguments),
                'rename_tags' => $this->stageRenameTags($user, $arguments),
                'create_transaction' => $this->stage('create_transaction', $tools->prepareCreateTransaction($user, $arguments)),
                'update_transaction' => $this->stage('update_transaction', $tools->prepareUpdateTransaction($user, $arguments)),
                'delete_transaction' => $this->stage('delete_transaction', $tools->prepareDeleteTransaction($user, $arguments)),
                'create_budget' => $this->stage('create_budget', $tools->prepareCreateBudget($user, $arguments)),
                default => AgentToolExecutor::fail("Unknown tool: {$name}"),
            };
        } catch (Throwable $e) {
            Log::warning('WebAgentChannel: tool failed', [
                'tool' => $name,
                'message' => $e->getMessage(),
            ]);

            return AgentToolExecutor::fail('Tool failed: ' . $e->getMessage());
        }
    }

    public function controlNote(string $control): ?string
    {
        return match ($control) {
            'ui_sent' => 'The card or chart was already shown in the chat. Do not repeat the full list; you may stop.',
            'ask_user' => 'Clarifying question was shown with reply buttons. Stop and wait for the user reply.',
            'confirm_staged' => 'A Confirm/Cancel card was shown. Stop and wait; do not claim the change is saved.',
            default => null,
        };
    }

    public function streams(): bool
    {
        return true;
    }

    public function onDelta(string $text): void
    {
        ($this->emit)('delta', ['text' => $text]);
    }

    public function onToolCall(string $name, array $arguments): void
    {
        ($this->emit)('tool', [
            'name' => $name,
            'label' => self::TOOL_LABELS[$name] ?? 'Working',
        ]);
    }

    public function onAnswer(string $text): void
    {
        $this->push('answer', ['role' => 'assistant', 'kind' => 'text', 'text' => mb_substr($text, 0, 8000)]);
        $this->session->setSummary(mb_substr($text, 0, 500));
    }

    public function onMaxRounds(): void
    {
        $this->push('notice', [
            'role' => 'assistant',
            'kind' => 'notice',
            'text' => 'I gathered some data but hit the step limit. Try a narrower question.',
        ]);
        $this->session->setSummary('Agent hit max rounds.');
    }

    public function quotaExhaustedMessage(User $user): string
    {
        return $this->quota->exhaustedMessage($user, self::WITHOUT_AI);
    }

    /**
     * Store a transcript item and send it to the browser.
     *
     * @param array<string, mixed> $item
     */
    public function push(string $event, array $item): array
    {
        $this->session->addItem($item);
        $items = $this->session->state()['transcript'];
        $stored = $items[count($items) - 1] ?? $item;
        ($this->emit)($event, ['item' => $stored]);

        return $stored;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, control: 'ui_sent', result: array<string, mixed>}
     */
    private function show(string $ui, string $view, string $title, array $data): array
    {
        $this->push('show', [
            'role' => 'assistant',
            'kind' => 'show',
            'view' => $view,
            'title' => $title,
            'data' => $data,
        ]);
        $this->session->setSummary($title . ' card shown.');

        return AgentToolExecutor::uiStop(['ui' => $ui, 'sent' => true, 'count' => $data['count'] ?? null]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok: bool, control: 'ui_sent', result: array<string, mixed>}
     */
    private function chart(User $user, array $args): array
    {
        $chart = $this->buildChart->execute($user, $args);

        $this->push('chart', [
            'role' => 'assistant',
            'kind' => 'chart',
            'chart' => $chart['data'],
            'caption' => $chart['caption'],
        ]);
        $this->session->setSummary(mb_substr('Chart shown: ' . str_replace("\n", '; ', $chart['caption']), 0, 500));

        return AgentToolExecutor::uiStop(['ui' => 'chart', 'sent' => true, 'summary' => $chart['summary']]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok: bool, control: 'ask_user', result: array<string, mixed>}
     */
    private function ask(array $args): array
    {
        ['question' => $question, 'suggestions' => $suggestions] = $this->tools->stageAsk($this->session, $args);

        $this->push('ask', [
            'role' => 'assistant',
            'kind' => 'ask',
            'question' => $question,
            'suggestions' => $suggestions,
            'status' => 'pending',
        ]);

        return AgentToolExecutor::askStop(['asked' => true, 'question' => $question, 'suggestions' => $suggestions]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok: bool, control: 'confirm_staged', result: array<string, mixed>}
     */
    private function stageCreateTags(User $user, array $args): array
    {
        $titles = $this->tools->prepareCreateTags($user, $args);
        $this->session->setDomain('tags');

        if ($titles === []) {
            $this->push('notice', [
                'role' => 'assistant',
                'kind' => 'notice',
                'text' => 'Those tags already exist (or none were left to create).',
            ]);
            $this->session->setSummary('No new tags were created.');

            return AgentToolExecutor::confirmStop(['staged' => false, 'reason' => 'nothing_to_create']);
        }

        $this->stage('create_tags', [
            'payload' => ['titles' => $titles],
            'preview' => 'Create tags: ' . implode(', ', $titles),
        ]);

        return AgentToolExecutor::confirmStop(['staged' => true, 'titles' => $titles]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok: bool, control: 'confirm_staged', result: array<string, mixed>}
     */
    private function stageRenameTags(User $user, array $args): array
    {
        $proposals = $this->tools->prepareRenameTags($user, $this->session, $args);

        if ($proposals === []) {
            $this->push('notice', [
                'role' => 'assistant',
                'kind' => 'notice',
                'text' => 'I could not match a tag and a new title. Try listing tags first, then rename with ids.',
            ]);
            $this->session->setSummary('Rename request had no valid tag proposals.');

            return AgentToolExecutor::confirmStop(['staged' => false, 'reason' => 'no_valid_proposals']);
        }

        $preview = "Rename tags:\n" . implode("\n", array_map(
            static fn (array $p): string => $p['before'] . ' → ' . $p['after'],
            $proposals,
        ));
        $this->stage('rename_tags', ['payload' => ['proposals' => $proposals], 'preview' => $preview]);

        return AgentToolExecutor::confirmStop(['staged' => true, 'proposals' => $proposals]);
    }

    /**
     * One pending slot per conversation: a newer preview replaces the older one,
     * whose card turns "outdated".
     *
     * @param array{payload: array<string, mixed>, preview: string} $prepared
     * @return array{ok: bool, control: 'confirm_staged', result: array<string, mixed>}
     */
    private function stage(string $action, array $prepared): array
    {
        $pendingId = bin2hex(random_bytes(8));

        $this->session->patchItems(
            static fn (array $item): bool => ($item['kind'] ?? null) === 'confirm' && ($item['status'] ?? null) === 'pending',
            ['status' => 'outdated'],
        );
        $this->session->setPending([
            'type' => 'agent_mutation',
            'action' => $action,
            'payload' => $prepared['payload'],
            'preview' => $prepared['preview'],
            'nonce' => $pendingId,
        ]);
        $this->push('confirm', [
            'role' => 'assistant',
            'kind' => 'confirm',
            'pending_id' => $pendingId,
            'action' => $action,
            'preview' => $prepared['preview'],
            'status' => 'pending',
        ]);
        $this->session->setSummary('Agent mutation preview shown.');

        return AgentToolExecutor::confirmStop(['staged' => true, 'payload' => $prepared['payload']]);
    }
}
