<?php

namespace App\Actions\Telegram;

use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Charts\BuildUserChartAction;
use App\Actions\Charts\ScreenshotHtmlViaGotenbergAction;
use App\Channels\Telegram\Handlers\MenuHandler;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Enums\TransactionSource;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram tool catalog + executor for the Grok agent.
 * Shared reads/validation/writes live in AgentToolExecutor; this class renders
 * results as Telegram cards and keyboards. Reads run immediately; writes only
 * stage a confirm (never mutate silently).
 */
class TelegramAgentToolExecutor
{
    /** Tools that stage a Confirm/Cancel preview instead of acting right away. */
    public const STAGING_TOOLS = AgentToolExecutor::STAGING_TOOLS;

    public const MAX_RECENT = AgentToolExecutor::MAX_RECENT;

    public const MAX_TRANSACTIONS_PAGE = AgentToolExecutor::MAX_TRANSACTIONS_PAGE;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly TelegramChatSession $session,
        private readonly MenuHandler $menuHandler,
        private readonly AgentToolExecutor $tools,
        private readonly BuildUserChartAction $buildChart,
        private readonly ScreenshotHtmlViaGotenbergAction $screenshot,
    ) {}

    /**
     * OpenAI-compatible tool definitions for chat.completions.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $fn = AgentToolExecutor::fn(...);

        return [
            $this->tools->definition('get_me'),
            $this->tools->definition('list_tags'),
            $fn('list_recent_transactions', 'Return the newest transactions (newest first). Prefer this for quick looks; use show_recent to send the standard Telegram card UI.', [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RECENT, 'description' => 'Max rows (default 10, max 20)'],
                ],
            ]),
            $this->tools->definition('list_transactions'),
            $this->tools->definition('list_budgets'),
            $this->tools->definition('list_recurring'),
            $this->tools->definition('period_summary'),
            $fn('show_menu', 'Send the standard reply-keyboard menu (Month, Recent, Tags, Budgets, Recurring, Web UI).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('show_help', 'Send the standard /help message (English UI).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('show_recent', 'Send the standard Recent transactions Telegram card (same as the Recent menu button).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('show_budgets', 'Send the standard Budgets Telegram card.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('show_tags', 'Send the standard Tags list and update session last_list.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('show_recurring', 'Send the standard Recurring rules card.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('show_month', 'Send the standard this-month summary card (same as Month / /month).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $fn('send_chart', 'Render a chart image (PNG) from the user\'s transactions and send it to the chat with a short caption, then stop. Use for any request to show/draw a chart, graph, diagram or visual breakdown (e.g. spending by tag, income vs expenses, balance trend).', self::chartParameters()),
            $fn('show_web', 'Send the Web UI / mini-app hint.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->tools->definition('ask_user'),
            $this->tools->definition('create_tags'),
            $this->tools->definition('rename_tags'),
            $this->tools->definition('create_transaction'),
            $this->tools->definition('update_transaction'),
            $this->tools->definition('delete_transaction'),
            $this->tools->definition('create_budget'),
        ];
    }

    /**
     * send_chart parameters (shared with the web agent).
     *
     * @return array<string, mixed>
     */
    public static function chartParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => BuildUserChartAction::TYPES, 'description' => 'expenses_by_tag (donut or bar), income_vs_expenses (grouped bars), balance_trend (line)'],
                'style' => ['type' => 'string', 'enum' => ['donut', 'bar'], 'description' => 'Only for expenses_by_tag; default donut'],
                'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Only for income_vs_expenses; default chosen by period length'],
                'period' => ['type' => 'string', 'enum' => BuildUserChartAction::PERIODS, 'description' => 'Default this_month. Ignored when from/to given.'],
                'from' => ['type' => 'string', 'description' => 'Explicit start YYYY-MM-DD (optional)'],
                'to' => ['type' => 'string', 'description' => 'Explicit end YYYY-MM-DD (optional)'],
            ],
            'required' => ['type'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{ok: bool, control: ?string, result: array<string, mixed>}
     */
    public function execute(
        User $user,
        int|string $chatId,
        int|string $identity,
        string $name,
        array $arguments,
    ): array {
        $session = new TelegramAgentSession($this->session, $identity, $chatId);

        try {
            return match ($name) {
                'get_me' => AgentToolExecutor::ok($this->tools->getMe($user)),
                'list_tags' => AgentToolExecutor::ok($this->tools->listTags($user, $session)),
                'list_recent_transactions' => AgentToolExecutor::ok($this->tools->listRecent($user, $session, $arguments)),
                'list_transactions' => AgentToolExecutor::ok($this->tools->listTransactions($user, $session, $arguments)),
                'list_budgets' => AgentToolExecutor::ok($this->tools->listBudgets($user, $session)),
                'list_recurring' => AgentToolExecutor::ok($this->tools->listRecurring($user)),
                'period_summary' => AgentToolExecutor::ok($this->tools->periodSummary($user, $arguments)),
                'show_menu' => AgentToolExecutor::uiStop($this->doShowMenu($chatId, $identity)),
                'show_help' => AgentToolExecutor::uiStop($this->doShowHelp($chatId, $identity)),
                'show_recent' => AgentToolExecutor::uiStop($this->doShowRecent($user, $chatId, $identity)),
                'show_budgets' => AgentToolExecutor::uiStop($this->doShowBudgets($user, $chatId, $identity)),
                'show_tags' => AgentToolExecutor::uiStop($this->doShowTags($user, $chatId, $identity)),
                'show_recurring' => AgentToolExecutor::uiStop($this->doShowRecurring($user, $chatId, $identity)),
                'show_month' => AgentToolExecutor::uiStop($this->doShowMonth($user, $chatId, $identity)),
                'send_chart' => AgentToolExecutor::uiStop($this->doSendChart($user, $chatId, $identity, $arguments)),
                'show_web' => AgentToolExecutor::uiStop($this->doShowWeb($chatId, $identity)),
                'ask_user' => AgentToolExecutor::askStop($this->doAskUser($chatId, $session, $arguments)),
                'create_tags' => AgentToolExecutor::confirmStop($this->stageCreateTags($user, $chatId, $identity, $arguments)),
                'rename_tags' => AgentToolExecutor::confirmStop($this->stageRenameTags($user, $chatId, $identity, $session, $arguments)),
                'create_transaction' => AgentToolExecutor::confirmStop($this->stageMutation($identity, $chatId, 'create_transaction', $this->tools->prepareCreateTransaction($user, $arguments))),
                'update_transaction' => AgentToolExecutor::confirmStop($this->stageMutation($identity, $chatId, 'update_transaction', $this->tools->prepareUpdateTransaction($user, $arguments))),
                'delete_transaction' => AgentToolExecutor::confirmStop($this->stageMutation($identity, $chatId, 'delete_transaction', $this->tools->prepareDeleteTransaction($user, $arguments))),
                'create_budget' => AgentToolExecutor::confirmStop($this->stageMutation($identity, $chatId, 'create_budget', $this->tools->prepareCreateBudget($user, $arguments))),
                default => AgentToolExecutor::fail("Unknown tool: {$name}"),
            };
        } catch (Throwable $e) {
            Log::warning('TelegramAgentToolExecutor: tool failed', [
                'tool' => $name,
                'message' => $e->getMessage(),
            ]);

            return AgentToolExecutor::fail('Tool failed: ' . $e->getMessage());
        }
    }

    /**
     * Apply a staged agent_mutation pending payload (after user Confirm).
     *
     * @param array<string, mixed> $pending
     */
    public function applyAgentMutation(User $user, int|string $chatId, int|string $identity, array $pending): void
    {
        $action = (string) ($pending['action'] ?? '');
        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];

        try {
            match ($action) {
                'create_transaction' => $this->applyCreateTransaction($user, $chatId, $identity, $payload),
                'update_transaction' => $this->applyUpdateTransaction($user, $chatId, $identity, $payload),
                'delete_transaction' => $this->applyDeleteTransaction($user, $chatId, $identity, $payload),
                'create_budget' => $this->applyCreateBudget($user, $chatId, $identity, $payload),
                default => $this->client->sendMessage($chatId, 'Nothing to confirm.'),
            };
        } catch (Throwable $e) {
            Log::warning('TelegramAgentToolExecutor: apply mutation failed', [
                'action' => $action,
                'message' => $e->getMessage(),
            ]);
            $this->client->sendMessage($chatId, 'Could not apply that change; nothing was saved.');
            $this->session->setSummary($identity, $chatId, 'Agent mutation failed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowMenu(int|string $chatId, int|string $identity): array
    {
        $this->client->sendMessage(
            $chatId,
            'Here is the menu — tap a button, or keep chatting in plain language.',
            $this->support->menuKeyboard(),
        );
        $this->session->setSummary($identity, $chatId, 'Reply menu sent.');

        return ['ui' => 'menu', 'sent' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowHelp(int|string $chatId, int|string $identity): array
    {
        $this->support->sendHelp($chatId);
        $this->session->setSummary($identity, $chatId, 'Help and capabilities sent.');

        return ['ui' => 'help', 'sent' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowRecent(User $user, int|string $chatId, int|string $identity): array
    {
        $this->menuHandler->sendRecent($user, $chatId, $identity);

        return ['ui' => 'recent', 'sent' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowBudgets(User $user, int|string $chatId, int|string $identity): array
    {
        $this->menuHandler->sendBudgets($user, $chatId, $identity);

        return ['ui' => 'budgets', 'sent' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowTags(User $user, int|string $chatId, int|string $identity): array
    {
        $this->menuHandler->sendTags($user, $chatId, $identity);

        return ['ui' => 'tags', 'sent' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowRecurring(User $user, int|string $chatId, int|string $identity): array
    {
        $this->menuHandler->sendRecurring($user, $chatId);
        $this->session->setSummary($identity, $chatId, 'Recurring rules listed.');

        return ['ui' => 'recurring', 'sent' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowMonth(User $user, int|string $chatId, int|string $identity): array
    {
        $this->menuHandler->sendMonthSummary($user, $chatId, $identity);

        return ['ui' => 'month', 'sent' => true];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function doSendChart(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        $chart = $this->buildChart->execute($user, $args);

        try {
            $png = $this->screenshot->execute($chart['html'], BuildUserChartAction::WIDTH, BuildUserChartAction::HEIGHT);
            $this->client->sendPhoto($chatId, $png, 'chart.png', $chart['caption']);
        } catch (Throwable $e) {
            Log::warning('TelegramAgentToolExecutor: chart render/send failed', ['message' => $e->getMessage()]);
            $this->client->sendMessage($chatId, "Couldn't render the chart right now.");
            $this->session->setSummary($identity, $chatId, 'Chart rendering failed.');

            return ['ui' => 'chart', 'sent' => false, 'error' => 'render_failed'];
        }

        $this->session->setSummary($identity, $chatId, mb_substr('Chart sent: ' . str_replace("\n", '; ', $chart['caption']), 0, 500));

        return ['ui' => 'chart', 'sent' => true, 'summary' => $chart['summary']];
    }

    /**
     * @return array<string, mixed>
     */
    private function doShowWeb(int|string $chatId, int|string $identity): array
    {
        $this->support->sendMiniAppHint($chatId);
        $this->session->setSummary($identity, $chatId, 'Web UI hint sent.');

        return ['ui' => 'web', 'sent' => true];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function doAskUser(int|string $chatId, TelegramAgentSession $session, array $args): array
    {
        ['question' => $question, 'suggestions' => $suggestions] = $this->tools->stageAsk($session, $args);

        $keyboard = $suggestions !== []
            ? $this->support->nlAskSuggestionsKeyboard($suggestions)
            : null;

        $this->client->sendMessage($chatId, $question, $keyboard);

        return ['asked' => true, 'question' => $question, 'suggestions' => $suggestions];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageCreateTags(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        $titles = $this->tools->prepareCreateTags($user, $args);
        $this->session->setDomain($identity, $chatId, 'tags');

        if ($titles === []) {
            $this->client->sendMessage($chatId, 'Those tags already exist (or none were left to create).');
            $this->session->setSummary($identity, $chatId, 'No new tags were created.');

            return ['staged' => false, 'reason' => 'nothing_to_create'];
        }

        $nonce = $this->support->newNonce();
        $this->session->setPending($identity, $chatId, [
            'type' => 'create_tags',
            'titles' => $titles,
            'nonce' => $nonce,
        ]);
        $label = implode(', ', $titles);
        $this->client->sendMessage(
            $chatId,
            "Create tags: {$label}?",
            $this->support->nlCreateTagsKeyboard($nonce),
        );
        $this->session->setSummary($identity, $chatId, 'Create-tag preview shown.');

        return ['staged' => true, 'titles' => $titles];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageRenameTags(User $user, int|string $chatId, int|string $identity, TelegramAgentSession $session, array $args): array
    {
        $proposals = $this->tools->prepareRenameTags($user, $session, $args);

        if ($proposals === []) {
            $this->client->sendMessage(
                $chatId,
                'I could not match a tag and a new title. Try listing tags first, then rename with ids.',
            );
            $this->session->setSummary($identity, $chatId, 'Rename request had no valid tag proposals.');

            return ['staged' => false, 'reason' => 'no_valid_proposals'];
        }

        $nonce = $this->support->newNonce();
        $this->session->setPending($identity, $chatId, [
            'type' => 'rename_tags',
            'proposals' => $proposals,
            'nonce' => $nonce,
        ]);
        $preview = implode("\n", array_map(
            static fn (array $p): string => $p['before'] . ' → ' . $p['after'],
            $proposals,
        ));
        $this->client->sendMessage(
            $chatId,
            "Rename tags:\n{$preview}\n\nApply these changes?",
            $this->support->nlRenameConfirmKeyboard($nonce),
        );
        $this->session->setSummary($identity, $chatId, 'Rename preview shown.');

        return ['staged' => true, 'proposals' => $proposals];
    }

    /**
     * @param array{payload: array<string, mixed>, preview: string} $prepared
     * @return array<string, mixed>
     */
    private function stageMutation(int|string $identity, int|string $chatId, string $action, array $prepared): array
    {
        // One slot per chat: a newer preview replaces this one, and the nonce
        // makes the older preview's buttons answer "outdated" instead of
        // applying the newer change.
        $nonce = $this->support->newNonce();
        $this->session->setPending($identity, $chatId, [
            'type' => 'agent_mutation',
            'action' => $action,
            'payload' => $prepared['payload'],
            'preview' => $prepared['preview'],
            'nonce' => $nonce,
        ]);
        $this->client->sendMessage(
            $chatId,
            $prepared['preview'] . "\n\nApply this change?",
            $this->support->nlAgentConfirmKeyboard($nonce),
        );
        $this->session->setSummary($identity, $chatId, 'Agent mutation preview shown.');

        return ['staged' => true, 'payload' => $prepared['payload']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreateTransaction(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $tx = $this->tools->applyCreateTransaction($user, $payload, TransactionSource::Telegram);

        $this->session->setSummary($identity, $chatId, 'Transaction created via agent confirm.');
        $this->support->sendSavedTransaction($chatId, $user, $tx, '✅ Saved');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyUpdateTransaction(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $tx = $this->tools->applyUpdateTransaction($user, $payload);
        if (!$tx) {
            $this->client->sendMessage($chatId, 'That transaction no longer exists.');

            return;
        }

        $this->session->setSummary($identity, $chatId, 'Transaction updated via agent confirm.');
        $this->support->sendSavedTransaction($chatId, $user, $tx, '✅ Updated');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyDeleteTransaction(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        if (!$this->tools->applyDeleteTransaction($user, $payload)) {
            $this->client->sendMessage($chatId, 'That transaction no longer exists.');

            return;
        }

        $this->session->setSummary($identity, $chatId, 'Transaction deleted via agent confirm.');
        $this->client->sendMessage($chatId, '✅ Transaction deleted.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreateBudget(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $budget = $this->tools->applyCreateBudget($user, $payload);
        if (!$budget) {
            $this->client->sendMessage($chatId, 'Those tags are no longer valid; budget was not created.');

            return;
        }

        $this->session->setSummary($identity, $chatId, 'Budget created via agent confirm.');
        $label = $budget->title ?: ('Budget #' . $budget->id);
        $this->client->sendMessage($chatId, '✅ Budget created: ' . $label);
    }
}
