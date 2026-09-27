<?php

namespace App\Actions\Telegram;

use App\Actions\Budgets\CalculateBudgetPeriodSpentAction;
use App\Actions\Budgets\CreateBudgetAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\Handlers\MenuHandler;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Enums\BudgetLength;
use App\Enums\TransactionSource;
use App\Models\BudgetPeriod;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client-side tool catalog + executor for the Telegram Grok agent.
 * Reads run immediately; writes only stage a confirm (never mutate silently).
 */
class TelegramAgentToolExecutor
{
    public const MAX_RECENT = 20;

    public const MAX_TRANSACTIONS_PAGE = 50;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly TelegramChatSession $session,
        private readonly MenuHandler $menuHandler,
        private readonly ListTagsAction $listTags,
        private readonly ListTransactionsAction $listTransactions,
        private readonly ListBudgetsAction $listBudgets,
        private readonly ListRecurringTransactionsAction $listRecurring,
        private readonly CalculateBudgetPeriodSpentAction $calculateBudgetSpent,
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly CreateBudgetAction $createBudget,
    ) {}

    /**
     * OpenAI-compatible tool definitions for chat.completions.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->fn('get_me', 'Return the linked FundsFlow user preferences (timezone, money format) and today\'s date in their timezone.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('list_tags', 'List all tags/categories for the account (id, emoji, title, parent_id).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('list_recent_transactions', 'Return the newest transactions (newest first). Prefer this for quick looks; use show_recent to send the standard Telegram card UI.', [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RECENT, 'description' => 'Max rows (default 10, max 20)'],
                ],
            ]),
            $this->fn('list_transactions', 'List transactions with optional filters (newest first). Caps at 50 rows.', [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD inclusive'],
                    'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD inclusive'],
                    'tag_id' => ['type' => 'integer', 'description' => 'Filter by tag id'],
                    'note_contains' => ['type' => 'string', 'description' => 'Case-insensitive note substring'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TRANSACTIONS_PAGE],
                ],
            ]),
            $this->fn('list_budgets', 'List budgets with current-period limit, spent, and progress percent.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('list_recurring', 'List recurring transaction rules.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('period_summary', 'Income, expenses, net, and top expense tags for a calendar month (user timezone).', [
                'type' => 'object',
                'properties' => [
                    'month' => ['type' => 'string', 'description' => 'YYYY-MM; default current month in user timezone'],
                ],
            ]),
            $this->fn('show_menu', 'Send the standard reply-keyboard menu (Month, Recent, Tags, Budgets, Recurring, Web UI).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_help', 'Send the standard /help message (English UI).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_recent', 'Send the standard Recent transactions Telegram card (same as the Recent menu button).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_budgets', 'Send the standard Budgets Telegram card.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_tags', 'Send the standard Tags list and update session last_list.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_recurring', 'Send the standard Recurring rules card.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_month', 'Send the standard this-month summary card (same as Month / /month).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('show_web', 'Send the Web UI / mini-app hint.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->fn('ask_user', 'Ask the user a clarifying question and stop. Optional English suggested replies as inline buttons. Button labels must be English.', [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => 'Question text (may match user language)'],
                    'suggestions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Up to 6 short English suggested replies',
                    ],
                ],
                'required' => ['question'],
            ]),
            $this->fn('create_tags', 'Propose creating one or more tags. Stages a Confirm/Cancel UI; does not create until the user confirms.', [
                'type' => 'object',
                'properties' => [
                    'titles' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => '1–10 tag titles to create',
                    ],
                ],
                'required' => ['titles'],
            ]),
            $this->fn('rename_tags', 'Propose renaming tags. Stages Confirm/Cancel. Use tag ids from list_tags / last_list; do not invent ids.', [
                'type' => 'object',
                'properties' => [
                    'proposals' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'before' => ['type' => 'string'],
                                'after' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'after'],
                        ],
                    ],
                ],
                'required' => ['proposals'],
            ]),
            $this->fn('create_transaction', 'Propose creating a transaction. Stages Confirm/Cancel. Amount signed: negative=expense, positive=income. Date YYYY-MM-DD.', [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number'],
                    'at' => ['type' => 'string', 'description' => 'YYYY-MM-DD; default today'],
                    'note' => ['type' => 'string'],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'required' => ['amount'],
            ]),
            $this->fn('update_transaction', 'Propose updating a transaction by id. Stages Confirm/Cancel.', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'amount' => ['type' => 'number'],
                    'at' => ['type' => 'string'],
                    'note' => ['type' => 'string'],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'required' => ['id'],
            ]),
            $this->fn('delete_transaction', 'Propose deleting a transaction by id. Stages Confirm/Cancel.', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ]),
            $this->fn('create_budget', 'Propose creating a budget. Stages Confirm/Cancel. length: week|month|year. tag_ids required.', [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'amount' => ['type' => 'number'],
                    'length' => ['type' => 'string', 'enum' => ['week', 'month', 'year']],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'align_to_calendar' => ['type' => 'boolean'],
                ],
                'required' => ['amount', 'length', 'tag_ids'],
            ]),
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
        try {
            return match ($name) {
                'get_me' => $this->ok($this->getMe($user)),
                'list_tags' => $this->ok($this->doListTags($user, $identity, $chatId)),
                'list_recent_transactions' => $this->ok($this->doListRecent($user, $identity, $chatId, $arguments)),
                'list_transactions' => $this->ok($this->doListTransactions($user, $identity, $chatId, $arguments)),
                'list_budgets' => $this->ok($this->doListBudgets($user, $identity, $chatId)),
                'list_recurring' => $this->ok($this->doListRecurring($user)),
                'period_summary' => $this->ok($this->doPeriodSummary($user, $arguments)),
                'show_menu' => $this->uiStop($this->doShowMenu($chatId, $identity)),
                'show_help' => $this->uiStop($this->doShowHelp($chatId, $identity)),
                'show_recent' => $this->uiStop($this->doShowRecent($user, $chatId, $identity)),
                'show_budgets' => $this->uiStop($this->doShowBudgets($user, $chatId, $identity)),
                'show_tags' => $this->uiStop($this->doShowTags($user, $chatId, $identity)),
                'show_recurring' => $this->uiStop($this->doShowRecurring($user, $chatId, $identity)),
                'show_month' => $this->uiStop($this->doShowMonth($user, $chatId, $identity)),
                'show_web' => $this->uiStop($this->doShowWeb($chatId, $identity)),
                'ask_user' => $this->askStop($this->doAskUser($chatId, $identity, $arguments)),
                'create_tags' => $this->confirmStop($this->stageCreateTags($user, $chatId, $identity, $arguments)),
                'rename_tags' => $this->confirmStop($this->stageRenameTags($user, $chatId, $identity, $arguments)),
                'create_transaction' => $this->confirmStop($this->stageCreateTransaction($user, $chatId, $identity, $arguments)),
                'update_transaction' => $this->confirmStop($this->stageUpdateTransaction($user, $chatId, $identity, $arguments)),
                'delete_transaction' => $this->confirmStop($this->stageDeleteTransaction($user, $chatId, $identity, $arguments)),
                'create_budget' => $this->confirmStop($this->stageCreateBudget($user, $chatId, $identity, $arguments)),
                default => $this->fail("Unknown tool: {$name}"),
            };
        } catch (Throwable $e) {
            Log::warning('TelegramAgentToolExecutor: tool failed', [
                'tool' => $name,
                'message' => $e->getMessage(),
            ]);

            return $this->fail('Tool failed: ' . $e->getMessage());
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
     * @param array{type: string, properties: array<string, mixed>|\stdClass, required?: list<string>} $parameters
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: null, result: array<string, mixed>}
     */
    private function ok(array $result): array
    {
        return ['ok' => true, 'control' => null, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: 'ui_sent', result: array<string, mixed>}
     */
    private function uiStop(array $result): array
    {
        return ['ok' => true, 'control' => 'ui_sent', 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: 'ask_user', result: array<string, mixed>}
     */
    private function askStop(array $result): array
    {
        return ['ok' => true, 'control' => 'ask_user', 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: 'confirm_staged', result: array<string, mixed>}
     */
    private function confirmStop(array $result): array
    {
        return ['ok' => true, 'control' => 'confirm_staged', 'result' => $result];
    }

    /**
     * @return array{ok: bool, control: null, result: array<string, mixed>}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'control' => null, 'result' => ['error' => $message]];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMe(User $user): array
    {
        $prefs = $user->resolvedPreferences();

        return [
            'user_id' => (int) $user->id,
            'email' => $user->email,
            'today' => $user->todayDateString(),
            'timezone' => $user->timezone(),
            'money_format' => $prefs['moneyFormat'] ?? null,
            'date_format' => $prefs['dateFormat'] ?? null,
            'decimals' => $prefs['decimals'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function doListTags(User $user, int|string $identity, int|string $chatId): array
    {
        $tags = $this->listTags->execute($user);
        $items = $tags->map(static fn (Tag $tag): array => [
            'id' => (int) $tag->id,
            'title' => (string) $tag->title,
            'emoji' => (string) $tag->emoji,
            'parent_id' => $tag->parent_id !== null ? (int) $tag->parent_id : null,
            'calc_balance' => (bool) $tag->calc_balance,
        ])->values()->all();

        $this->session->setDomain($identity, $chatId, 'tags');
        $this->session->setLastList($identity, $chatId, array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['title'],
                'emoji' => $item['emoji'],
            ],
            $items,
        ));

        return ['count' => count($items), 'tags' => $items];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function doListRecent(User $user, int|string $identity, int|string $chatId, array $args): array
    {
        $limit = $this->clampInt($args['limit'] ?? 10, 1, self::MAX_RECENT);
        $transactions = $this->listTransactions->execute($user)->take($limit)->values();
        $items = $transactions->map(fn (Transaction $tx): array => $this->serializeTransaction($user, $tx))->all();

        $this->session->setDomain($identity, $chatId, 'transactions');
        $this->session->setLastList($identity, $chatId, array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['note'] !== '' ? $item['note'] : $item['amount_formatted'],
            ],
            $items,
        ));

        return ['count' => count($items), 'transactions' => $items];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function doListTransactions(User $user, int|string $identity, int|string $chatId, array $args): array
    {
        $limit = $this->clampInt($args['limit'] ?? 30, 1, self::MAX_TRANSACTIONS_PAGE);
        $from = $this->optionalDate($args['from'] ?? null);
        $to = $this->optionalDate($args['to'] ?? null);
        $tagId = isset($args['tag_id']) && is_numeric($args['tag_id']) ? (int) $args['tag_id'] : null;
        $noteContains = is_string($args['note_contains'] ?? null) ? trim((string) $args['note_contains']) : '';

        $transactions = $this->listTransactions->execute($user)->filter(function (Transaction $tx) use ($from, $to, $tagId, $noteContains): bool {
            $at = $tx->at?->format('Y-m-d');
            if ($from !== null && ($at === null || $at < $from)) {
                return false;
            }
            if ($to !== null && ($at === null || $at > $to)) {
                return false;
            }
            if ($tagId !== null && !$tx->tags->contains(fn (Tag $tag): bool => (int) $tag->id === $tagId)) {
                return false;
            }
            if ($noteContains !== '' && !str_contains(mb_strtolower((string) ($tx->note ?? '')), mb_strtolower($noteContains))) {
                return false;
            }

            return true;
        })->take($limit)->values();

        $items = $transactions->map(fn (Transaction $tx): array => $this->serializeTransaction($user, $tx))->all();

        $this->session->setDomain($identity, $chatId, 'transactions');
        $this->session->setLastList($identity, $chatId, array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['note'] !== '' ? $item['note'] : $item['amount_formatted'],
            ],
            $items,
        ));

        return [
            'count' => count($items),
            'filters' => [
                'from' => $from,
                'to' => $to,
                'tag_id' => $tagId,
                'note_contains' => $noteContains !== '' ? $noteContains : null,
                'limit' => $limit,
            ],
            'transactions' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function doListBudgets(User $user, int|string $identity, int|string $chatId): array
    {
        $budgets = $this->listBudgets->execute($user);
        $items = [];
        $list = [];

        foreach ($budgets as $budget) {
            $period = $budget->periods->first(fn (BudgetPeriod $period) => $period->ends_at === null);
            if (!$period) {
                continue;
            }

            $label = $budget->title
                ?: $period->tags->map(fn (Tag $tag) => trim($tag->emoji . ' ' . $tag->title))->filter()->implode(', ')
                ?: 'Budget #' . $budget->id;

            $spent = $this->calculateBudgetSpent->execute($period, $user);
            $limit = (float) $period->amount;
            $pct = $limit > 0 ? (int) round(($spent / $limit) * 100) : 0;

            $items[] = [
                'id' => (int) $budget->id,
                'title' => $label,
                'amount_limit' => $limit,
                'amount_limit_formatted' => UserFormatter::formatMoney($user, $limit),
                'spent' => $spent,
                'spent_formatted' => UserFormatter::formatMoney($user, $spent),
                'percent' => $pct,
                'length' => $period->length?->value ?? (string) $period->length,
                'tag_ids' => $period->tags->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
            $list[] = ['id' => (int) $budget->id, 'title' => $label];
        }

        $this->session->setDomain($identity, $chatId, 'budgets');
        $this->session->setLastList($identity, $chatId, $list);

        return ['count' => count($items), 'budgets' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function doListRecurring(User $user): array
    {
        $rules = $this->listRecurring->execute($user);
        $items = $rules->map(function ($rule) use ($user): array {
            return [
                'id' => (int) $rule->id,
                'amount' => (float) $rule->amount,
                'amount_formatted' => UserFormatter::formatMoney($user, (float) $rule->amount),
                'note' => (string) ($rule->note ?? ''),
                'frequency' => $rule->frequency->value,
                'active' => (bool) $rule->active,
                'next_run_at' => $rule->next_run_at?->format('Y-m-d'),
                'tag_ids' => $rule->tags->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        })->values()->all();

        return ['count' => count($items), 'recurring' => $items];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function doPeriodSummary(User $user, array $args): array
    {
        $month = is_string($args['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $args['month'])
            ? $args['month']
            : $user->nowInTimezone()->format('Y-m');

        $transactions = $this->listTransactions->execute($user)
            ->filter(fn (Transaction $tx) => $tx->at && $tx->at->format('Y-m') === $month);

        $income = (float) $transactions->filter(fn (Transaction $tx) => $tx->amount > 0)->sum('amount');
        $expense = (float) $transactions->filter(fn (Transaction $tx) => $tx->amount < 0)->sum('amount');

        $byTag = [];
        foreach ($transactions->filter(fn (Transaction $tx) => $tx->amount < 0) as $tx) {
            foreach ($tx->tags as $tag) {
                $byTag[$tag->id] ??= [
                    'id' => (int) $tag->id,
                    'title' => (string) $tag->title,
                    'emoji' => (string) $tag->emoji,
                    'amount' => 0.0,
                ];
                $byTag[$tag->id]['amount'] += abs((float) $tx->amount);
            }
        }

        $top = collect($byTag)->sortByDesc('amount')->take(5)->values()->map(function (array $row) use ($user): array {
            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'emoji' => $row['emoji'],
                'amount' => $row['amount'],
                'amount_formatted' => UserFormatter::formatMoney($user, -1 * $row['amount']),
            ];
        })->all();

        return [
            'month' => $month,
            'income' => $income,
            'income_formatted' => UserFormatter::formatMoney($user, $income),
            'expenses' => $expense,
            'expenses_formatted' => UserFormatter::formatMoney($user, $expense),
            'net' => $income + $expense,
            'net_formatted' => UserFormatter::formatMoney($user, $income + $expense),
            'transaction_count' => $transactions->count(),
            'top_expense_tags' => $top,
        ];
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
    private function doAskUser(int|string $chatId, int|string $identity, array $args): array
    {
        $question = trim((string) ($args['question'] ?? ''));
        if ($question === '') {
            throw new \InvalidArgumentException('question is required');
        }
        $question = mb_substr($question, 0, 2000);

        $suggestions = [];
        if (is_array($args['suggestions'] ?? null)) {
            foreach ($args['suggestions'] as $suggestion) {
                if (!is_string($suggestion)) {
                    continue;
                }
                $label = mb_substr(trim($suggestion), 0, 64);
                if ($label !== '') {
                    $suggestions[] = $label;
                }
                if (count($suggestions) >= 6) {
                    break;
                }
            }
        }

        $this->session->setPending($identity, $chatId, [
            'type' => 'agent_ask',
            'question' => $question,
            'suggestions' => $suggestions,
        ]);
        $this->session->setSummary($identity, $chatId, 'Waiting for user answer.');

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
        $titles = $this->parseTitles($args['titles'] ?? []);
        $titles = $this->filterNewTagTitles($user, $titles);
        $this->session->setDomain($identity, $chatId, 'tags');

        if ($titles === []) {
            $this->client->sendMessage($chatId, 'Those tags already exist (or none were left to create).');
            $this->session->setSummary($identity, $chatId, 'No new tags were created.');

            return ['staged' => false, 'reason' => 'nothing_to_create'];
        }

        $this->session->setPending($identity, $chatId, [
            'type' => 'create_tags',
            'titles' => $titles,
        ]);
        $label = implode(', ', $titles);
        $this->client->sendMessage(
            $chatId,
            "Create tags: {$label}?",
            $this->support->nlCreateTagsKeyboard(),
        );
        $this->session->setSummary($identity, $chatId, 'Create-tag preview shown.');

        return ['staged' => true, 'titles' => $titles];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageRenameTags(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        $raw = is_array($args['proposals'] ?? null) ? $args['proposals'] : [];
        $proposals = $this->resolveRenameProposals($user, $identity, $chatId, $raw);

        if ($proposals === []) {
            $this->client->sendMessage(
                $chatId,
                'I could not match a tag and a new title. Try listing tags first, then rename with ids.',
            );
            $this->session->setSummary($identity, $chatId, 'Rename request had no valid tag proposals.');

            return ['staged' => false, 'reason' => 'no_valid_proposals'];
        }

        $this->session->setPending($identity, $chatId, [
            'type' => 'rename_tags',
            'proposals' => $proposals,
        ]);
        $preview = implode("\n", array_map(
            static fn (array $p): string => $p['before'] . ' → ' . $p['after'],
            $proposals,
        ));
        $this->client->sendMessage(
            $chatId,
            "Rename tags:\n{$preview}\n\nApply these changes?",
            $this->support->nlRenameConfirmKeyboard(),
        );
        $this->session->setSummary($identity, $chatId, 'Rename preview shown.');

        return ['staged' => true, 'proposals' => $proposals];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageCreateTransaction(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        if (!isset($args['amount']) || !is_numeric($args['amount'])) {
            throw new \InvalidArgumentException('amount is required');
        }
        $amount = (float) $args['amount'];
        if ($amount == 0.0) {
            throw new \InvalidArgumentException('amount must be non-zero');
        }

        $at = $this->optionalDate($args['at'] ?? null) ?? $user->todayDateString();
        $note = is_string($args['note'] ?? null) ? mb_substr(trim((string) $args['note']), 0, 255) : null;
        $tagIds = $this->sanitizeOwnedTagIds($user, $args['tag_ids'] ?? []);

        $payload = [
            'amount' => $amount,
            'at' => $at,
            'note' => $note,
            'tag_ids' => $tagIds,
        ];

        $preview = 'Create transaction:' . "\n"
            . UserFormatter::formatMoney($user, $amount) . ' on ' . $at
            . ($note ? "\nNote: {$note}" : '')
            . ($tagIds !== [] ? "\nTags: " . $this->tagLabels($user, $tagIds) : '');

        $this->stageAgentMutation($identity, $chatId, 'create_transaction', $payload, $preview);

        return ['staged' => true, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageUpdateTransaction(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        $id = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : 0;
        $tx = $user->transactions()->with('tags')->find($id);
        if (!$tx) {
            throw new \InvalidArgumentException('transaction not found');
        }

        $amount = array_key_exists('amount', $args) && is_numeric($args['amount'])
            ? (float) $args['amount']
            : (float) $tx->amount;
        $at = $this->optionalDate($args['at'] ?? null) ?? $tx->at->format('Y-m-d');
        $note = array_key_exists('note', $args)
            ? (is_string($args['note']) ? mb_substr(trim($args['note']), 0, 255) : null)
            : $tx->note;
        $tagIds = array_key_exists('tag_ids', $args)
            ? $this->sanitizeOwnedTagIds($user, $args['tag_ids'])
            : $tx->tags->pluck('id')->map(fn ($id) => (int) $id)->all();

        $payload = [
            'id' => $id,
            'amount' => $amount,
            'at' => $at,
            'note' => $note,
            'tag_ids' => $tagIds,
        ];

        $preview = "Update transaction #{$id}:\n"
            . UserFormatter::formatMoney($user, $amount) . ' on ' . $at
            . ($note ? "\nNote: {$note}" : '')
            . ($tagIds !== [] ? "\nTags: " . $this->tagLabels($user, $tagIds) : '');

        $this->stageAgentMutation($identity, $chatId, 'update_transaction', $payload, $preview);

        return ['staged' => true, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageDeleteTransaction(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        $id = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : 0;
        $tx = $user->transactions()->with('tags')->find($id);
        if (!$tx) {
            throw new \InvalidArgumentException('transaction not found');
        }

        $payload = ['id' => $id];
        $preview = "Delete transaction #{$id}:\n"
            . UserFormatter::formatMoney($user, (float) $tx->amount)
            . ' on ' . $tx->at->format('Y-m-d')
            . ($tx->note ? "\nNote: {$tx->note}" : '');

        $this->stageAgentMutation($identity, $chatId, 'delete_transaction', $payload, $preview);

        return ['staged' => true, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function stageCreateBudget(User $user, int|string $chatId, int|string $identity, array $args): array
    {
        if (!isset($args['amount']) || !is_numeric($args['amount'])) {
            throw new \InvalidArgumentException('amount is required');
        }
        $amount = (float) $args['amount'];
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be positive');
        }

        $length = (string) ($args['length'] ?? '');
        if (!in_array($length, ['week', 'month', 'year'], true)) {
            throw new \InvalidArgumentException('length must be week|month|year');
        }

        $tagIds = $this->sanitizeOwnedTagIds($user, $args['tag_ids'] ?? []);
        if ($tagIds === []) {
            throw new \InvalidArgumentException('tag_ids required (at least one owned tag)');
        }

        $title = is_string($args['title'] ?? null) ? mb_substr(trim((string) $args['title']), 0, 255) : null;
        $align = (bool) ($args['align_to_calendar'] ?? false);

        $payload = [
            'title' => $title !== '' ? $title : null,
            'amount' => $amount,
            'length' => $length,
            'tag_ids' => $tagIds,
            'align_to_calendar' => $align,
        ];

        $preview = 'Create budget:' . "\n"
            . ($title ? "{$title}\n" : '')
            . UserFormatter::formatMoney($user, $amount) . " / {$length}\n"
            . 'Tags: ' . $this->tagLabels($user, $tagIds);

        $this->stageAgentMutation($identity, $chatId, 'create_budget', $payload, $preview);

        return ['staged' => true, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stageAgentMutation(
        int|string $identity,
        int|string $chatId,
        string $action,
        array $payload,
        string $preview,
    ): void {
        $this->session->setPending($identity, $chatId, [
            'type' => 'agent_mutation',
            'action' => $action,
            'payload' => $payload,
            'preview' => $preview,
        ]);
        $this->client->sendMessage(
            $chatId,
            $preview . "\n\nApply this change?",
            $this->support->nlAgentConfirmKeyboard(),
        );
        $this->session->setSummary($identity, $chatId, 'Agent mutation preview shown.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreateTransaction(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $tagIds = $this->sanitizeOwnedTagIds($user, $payload['tag_ids'] ?? []);
        $tx = $this->createTransaction->execute($user, [
            'amount' => (float) $payload['amount'],
            'at' => (string) $payload['at'],
            'note' => $payload['note'] ?? null,
            'tags' => $tagIds,
        ], TransactionSource::Telegram);

        $this->session->setSummary($identity, $chatId, 'Transaction created via agent confirm.');
        $this->support->sendSavedTransaction($chatId, $user, $tx, '✅ Saved');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyUpdateTransaction(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $id = (int) ($payload['id'] ?? 0);
        $tx = $user->transactions()->find($id);
        if (!$tx) {
            $this->client->sendMessage($chatId, 'That transaction no longer exists.');

            return;
        }

        $tagIds = $this->sanitizeOwnedTagIds($user, $payload['tag_ids'] ?? []);
        $tx = $this->updateTransaction->execute($user, $tx, [
            'amount' => (float) $payload['amount'],
            'at' => (string) $payload['at'],
            'note' => $payload['note'] ?? null,
            'tags' => $tagIds,
        ]);

        $this->session->setSummary($identity, $chatId, 'Transaction updated via agent confirm.');
        $this->support->sendSavedTransaction($chatId, $user, $tx, '✅ Updated');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyDeleteTransaction(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $id = (int) ($payload['id'] ?? 0);
        $tx = $user->transactions()->find($id);
        if (!$tx) {
            $this->client->sendMessage($chatId, 'That transaction no longer exists.');

            return;
        }

        $this->deleteTransaction->execute($user, $tx);
        $this->session->setSummary($identity, $chatId, 'Transaction deleted via agent confirm.');
        $this->client->sendMessage($chatId, '✅ Transaction deleted.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCreateBudget(User $user, int|string $chatId, int|string $identity, array $payload): void
    {
        $tagIds = $this->sanitizeOwnedTagIds($user, $payload['tag_ids'] ?? []);
        if ($tagIds === []) {
            $this->client->sendMessage($chatId, 'Those tags are no longer valid; budget was not created.');

            return;
        }

        BudgetLength::from((string) $payload['length']);
        $budget = $this->createBudget->execute($user, [
            'title' => $payload['title'] ?? null,
            'amount' => (float) $payload['amount'],
            'length' => (string) $payload['length'],
            'tag_ids' => $tagIds,
            'align_to_calendar' => (bool) ($payload['align_to_calendar'] ?? false),
        ]);

        $this->session->setSummary($identity, $chatId, 'Budget created via agent confirm.');
        $label = $budget->title ?: ('Budget #' . $budget->id);
        $this->client->sendMessage($chatId, '✅ Budget created: ' . $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(User $user, Transaction $tx): array
    {
        return [
            'id' => (int) $tx->id,
            'amount' => (float) $tx->amount,
            'amount_formatted' => UserFormatter::formatMoney($user, (float) $tx->amount),
            'at' => $tx->at?->format('Y-m-d'),
            'note' => (string) ($tx->note ?? ''),
            'source' => $tx->source?->value,
            'tags' => $tx->tags->map(static fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'title' => (string) $tag->title,
                'emoji' => (string) $tag->emoji,
            ])->values()->all(),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function parseTitles(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $titles = [];
        foreach ($raw as $title) {
            if (!is_string($title)) {
                continue;
            }
            $title = mb_substr(trim($title), 0, 255);
            if ($title === '') {
                continue;
            }
            $titles[] = $title;
        }

        return array_values(array_unique(array_slice($titles, 0, 10)));
    }

    /**
     * @param list<string> $titles
     * @return list<string>
     */
    private function filterNewTagTitles(User $user, array $titles): array
    {
        $existing = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $existing[mb_strtolower(trim((string) $tag->title))] = true;
        }

        $out = [];
        foreach ($titles as $title) {
            $key = mb_strtolower(trim($title));
            if ($key === '' || isset($existing[$key])) {
                continue;
            }
            $existing[$key] = true;
            $out[] = mb_substr(trim($title), 0, 255);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rawProposals
     * @return list<array{id: int, before: string, after: string}>
     */
    private function resolveRenameProposals(
        User $user,
        int|string $identity,
        int|string $chatId,
        array $rawProposals,
    ): array {
        $session = $this->session->get($identity, $chatId);
        $lastList = $session['last_list'];
        $byId = [];
        foreach ($lastList as $item) {
            $byId[(int) $item['id']] = true;
        }

        // If last_list empty, allow ids that exist on the account.
        $tags = $this->listTags->execute($user)->keyBy('id');
        $out = [];
        $usedAfter = [];

        foreach ($rawProposals as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }
            $id = is_numeric($proposal['id'] ?? null) ? (int) $proposal['id'] : null;
            if ($id === null) {
                continue;
            }
            if ($byId !== [] && !isset($byId[$id]) && !$tags->has($id)) {
                continue;
            }

            /** @var Tag|null $tag */
            $tag = $tags->get($id);
            if (!$tag) {
                continue;
            }

            $before = trim((string) ($proposal['before'] ?? ''));
            if ($before !== '' && mb_strtolower($before) !== mb_strtolower($tag->title)) {
                continue;
            }

            $after = mb_substr(trim((string) ($proposal['after'] ?? '')), 0, 255);
            if ($after === '' || mb_strtolower($after) === mb_strtolower($tag->title)) {
                continue;
            }

            $afterKey = mb_strtolower($after);
            $duplicate = $tags->contains(function (Tag $other) use ($afterKey, $id): bool {
                return (int) $other->id !== $id && mb_strtolower(trim($other->title)) === $afterKey;
            });
            if ($duplicate || isset($usedAfter[$afterKey])) {
                continue;
            }

            $usedAfter[$afterKey] = true;
            $out[] = [
                'id' => $id,
                'before' => $tag->title,
                'after' => $after,
            ];
        }

        return array_slice($out, 0, 50);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function sanitizeOwnedTagIds(User $user, mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $owned = $this->listTags->execute($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ownedSet = array_fill_keys($owned, true);
        $ids = [];
        foreach ($raw as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $id = (int) $id;
            if ($id > 0 && isset($ownedSet[$id])) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $tagIds
     */
    private function tagLabels(User $user, array $tagIds): string
    {
        $tags = $this->listTags->execute($user)->keyBy('id');
        $labels = [];
        foreach ($tagIds as $id) {
            /** @var Tag|null $tag */
            $tag = $tags->get($id);
            if ($tag) {
                $labels[] = trim($tag->emoji . ' ' . $tag->title);
            }
        }

        return implode(', ', $labels);
    }

    private function optionalDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $n = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $n));
    }
}
