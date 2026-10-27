<?php

namespace App\Actions\Agent;

use App\Actions\Budgets\CreateBudgetAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Tags\UpdateTagAction;
use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Enums\BudgetLength;
use App\Enums\TransactionSource;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\DB;

/**
 * Transport-agnostic agent tools: shared tool schemas, read tools, validation of
 * staged mutations (payload + preview text), and applying a confirmed mutation.
 * Transports (Telegram, web) decide how results, previews and confirmations are shown.
 */
class AgentToolExecutor
{
    /** Tools that stage a Confirm/Cancel preview instead of acting right away. */
    public const STAGING_TOOLS = [
        'create_tags',
        'rename_tags',
        'create_transaction',
        'update_transaction',
        'delete_transaction',
        'create_budget',
        ...AgentPlanningTools::STAGING_TOOLS,
    ];

    public const MAX_RECENT = 20;

    public const MAX_TRANSACTIONS_PAGE = 50;

    public const DEFAULT_TAG_EMOJI = '🏷';

    public function __construct(
        private readonly ListTagsAction $listTags,
        private readonly ListTransactionsAction $listTransactions,
        private readonly ListBudgetsAction $listBudgets,
        private readonly ListRecurringTransactionsAction $listRecurring,
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly CreateBudgetAction $createBudget,
        private readonly CreateTagAction $createTag,
        private readonly UpdateTagAction $updateTag,
        /** Budgets status/edits, recurring rules, upcoming charges, period comparison. */
        public readonly AgentPlanningTools $planning,
    ) {}

    /**
     * Schema for a tool whose name, description and parameters are the same on every transport.
     *
     * @return array<string, mixed>
     */
    public function definition(string $name): array
    {
        return match ($name) {
            'get_me' => self::fn('get_me', 'Return the linked FundsFlow user preferences (timezone, money format) and today\'s date in their timezone.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            'list_tags' => self::fn('list_tags', 'List all tags/categories for the account (id, emoji, title, parent_id).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            'list_transactions' => self::fn('list_transactions', 'List transactions with optional filters (newest first). Caps at 50 rows.', [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD inclusive'],
                    'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD inclusive'],
                    'tag_id' => ['type' => 'integer', 'description' => 'Filter by tag id'],
                    'note_contains' => ['type' => 'string', 'description' => 'Case-insensitive note substring'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TRANSACTIONS_PAGE],
                ],
            ]),
            'list_budgets' => self::fn('list_budgets', 'List active budgets: spending limits (type limit: the current period with its dates, limit, spent, left, percent rounded down, days left, schedule) and savings goals (type goal: saved of target, remaining, percent, deadline, status completed|on_track|behind|in_progress).', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            'list_recurring' => self::fn('list_recurring', 'List recurring transaction rules.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            'period_summary' => self::fn('period_summary', 'Income, expenses, net, and top expense tags for a calendar month (user timezone).', [
                'type' => 'object',
                'properties' => [
                    'month' => ['type' => 'string', 'description' => 'YYYY-MM; default current month in user timezone'],
                ],
            ]),
            'ask_user' => self::fn('ask_user', 'Ask the user a clarifying question and stop. Optional English suggested replies as inline buttons. Button labels must be English.', [
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
            'create_tags' => self::fn('create_tags', 'Propose creating one or more tags. Stages a Confirm/Cancel UI; does not create until the user confirms.', [
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
            'rename_tags' => self::fn('rename_tags', 'Propose renaming tags. Stages Confirm/Cancel. Use tag ids from list_tags / last_list; do not invent ids.', [
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
            'create_transaction' => self::fn('create_transaction', 'Propose creating a transaction. Stages Confirm/Cancel. Amount signed: negative=expense, positive=income. Date YYYY-MM-DD.', [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number'],
                    'at' => ['type' => 'string', 'description' => 'YYYY-MM-DD; default today'],
                    'note' => ['type' => 'string'],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'required' => ['amount'],
            ]),
            'update_transaction' => self::fn('update_transaction', 'Propose updating a transaction by id. Stages Confirm/Cancel.', [
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
            'delete_transaction' => self::fn('delete_transaction', 'Propose deleting a transaction by id. Stages Confirm/Cancel.', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ]),
            'create_budget' => self::fn('create_budget', 'Propose creating a budget. Stages Confirm/Cancel. type limit (default): a spending limit; length week|month|year required; optional start_date YYYY-MM-DD (today or earlier): the limit starts that day and its periods repeat from it (monthly from the 15th runs 15th → 14th, weekly from that weekday); without start_date it starts at the beginning of the current calendar week/month/year (so spending already made this period counts) — pass today\'s date only if the user wants it to start today. align_to_calendar is a legacy flag (true = start of the current calendar period). type goal: a savings goal; amount is the target, tag_ids are the savings tags whose transactions count as contributions from the start date (transfers into savings logged as expenses add to it, withdrawals subtract), optional start_date (YYYY-MM-DD, default today, may be in the past: contributions count from that day), target_date deadline (today or later) and start_amount already saved; no length. tag_ids required for both.', [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['limit', 'goal']],
                    'title' => ['type' => 'string'],
                    'amount' => ['type' => 'number', 'description' => 'Limit per period, or the goal target'],
                    'length' => ['type' => 'string', 'enum' => ['week', 'month', 'year'], 'description' => 'Limits only (required)'],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'align_to_calendar' => ['type' => 'boolean', 'description' => 'Limits only, legacy: true = start at the current calendar week/month/year (the default when start_date is omitted)'],
                    'start_date' => ['type' => 'string', 'description' => 'Optional start YYYY-MM-DD, today or earlier. Limits: periods repeat from this day (default: start of the current calendar week/month/year). Goals: progress counts transactions from this day (default today; not after target_date)'],
                    'target_date' => ['type' => 'string', 'description' => 'Goals only: optional deadline YYYY-MM-DD, today or later'],
                    'start_amount' => ['type' => 'number', 'description' => 'Goals only: optional amount already saved before tracking'],
                ],
                'required' => ['amount', 'tag_ids'],
            ]),
            default => throw new \InvalidArgumentException("No shared definition for tool: {$name}"),
        };
    }

    /**
     * @param array{type: string, properties: array<string, mixed>|\stdClass, required?: list<string>} $parameters
     * @return array<string, mixed>
     */
    public static function fn(string $name, string $description, array $parameters): array
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
    public static function ok(array $result): array
    {
        return ['ok' => true, 'control' => null, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: 'ui_sent', result: array<string, mixed>}
     */
    public static function uiStop(array $result): array
    {
        return ['ok' => true, 'control' => 'ui_sent', 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: 'ask_user', result: array<string, mixed>}
     */
    public static function askStop(array $result): array
    {
        return ['ok' => true, 'control' => 'ask_user', 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, control: 'confirm_staged', result: array<string, mixed>}
     */
    public static function confirmStop(array $result): array
    {
        return ['ok' => true, 'control' => 'confirm_staged', 'result' => $result];
    }

    /**
     * @return array{ok: bool, control: null, result: array<string, mixed>}
     */
    public static function fail(string $message): array
    {
        return ['ok' => false, 'control' => null, 'result' => ['error' => $message]];
    }

    // ---------------------------------------------------------------------
    // Read tools
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getMe(User $user): array
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
    public function listTags(User $user, AgentSession $session): array
    {
        $tags = $this->listTags->execute($user);
        $items = $tags->map(static fn (Tag $tag): array => [
            'id' => (int) $tag->id,
            'title' => (string) $tag->title,
            'emoji' => (string) $tag->emoji,
            'parent_id' => $tag->parent_id !== null ? (int) $tag->parent_id : null,
            'calc_balance' => (bool) $tag->calc_balance,
        ])->values()->all();

        $session->setDomain('tags');
        $session->setLastList(array_map(
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
    public function listRecent(User $user, AgentSession $session, array $args): array
    {
        $limit = $this->clampInt($args['limit'] ?? 10, 1, self::MAX_RECENT);
        $transactions = $this->listTransactions->execute($user)->take($limit)->values();
        $items = $transactions->map(fn (Transaction $tx): array => $this->serializeTransaction($user, $tx))->all();

        $session->setDomain('transactions');
        $session->setLastList(array_map(
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
    public function listTransactions(User $user, AgentSession $session, array $args): array
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

        $session->setDomain('transactions');
        $session->setLastList(array_map(
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
    public function listBudgets(User $user, AgentSession $session): array
    {
        $budgets = $this->listBudgets->execute($user);
        $now = $user->nowInTimezone();
        $items = [];
        $list = [];

        foreach ($budgets as $budget) {
            $period = $budget->periods->first(fn (BudgetPeriod $period) => $period->ends_at === null);
            if (!$period) {
                continue;
            }

            $label = $budget->label($period);
            $items[] = $budget->isGoal()
                ? $this->planning->goalStatus($user, $budget, $label)
                : $this->planning->budgetPeriodStatus($user, $budget, $period, $now, $label);
            $list[] = ['id' => (int) $budget->id, 'title' => $label];
        }

        $session->setDomain('budgets');
        $session->setLastList($list);

        return ['count' => count($items), 'budgets' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function listRecurring(User $user): array
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
    public function periodSummary(User $user, array $args): array
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

    // ---------------------------------------------------------------------
    // ask_user
    // ---------------------------------------------------------------------

    /**
     * Validate ask_user arguments and store the agent_ask pending slot.
     *
     * @param array<string, mixed> $args
     * @return array{question: string, suggestions: list<string>}
     */
    public function stageAsk(AgentSession $session, array $args): array
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

        $session->setPending([
            'type' => 'agent_ask',
            'question' => $question,
            'suggestions' => $suggestions,
        ]);
        $session->setSummary('Waiting for user answer.');

        return ['question' => $question, 'suggestions' => $suggestions];
    }

    // ---------------------------------------------------------------------
    // Staged mutations: validation + preview text (no writes)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     * @return list<string> New (not yet existing) tag titles.
     */
    public function prepareCreateTags(User $user, array $args): array
    {
        return $this->filterNewTagTitles($user, $this->parseTitles($args['titles'] ?? []));
    }

    /**
     * @param array<string, mixed> $args
     * @return list<array{id: int, before: string, after: string}>
     */
    public function prepareRenameTags(User $user, AgentSession $session, array $args): array
    {
        $raw = is_array($args['proposals'] ?? null) ? $args['proposals'] : [];

        return $this->resolveRenameProposals($user, $session, $raw);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    public function prepareCreateTransaction(User $user, array $args): array
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

        return ['payload' => $payload, 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    public function prepareUpdateTransaction(User $user, array $args): array
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

        return ['payload' => $payload, 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    public function prepareDeleteTransaction(User $user, array $args): array
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

        return ['payload' => $payload, 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    public function prepareCreateBudget(User $user, array $args): array
    {
        if (!isset($args['amount']) || !is_numeric($args['amount'])) {
            throw new \InvalidArgumentException('amount is required');
        }
        $amount = (float) $args['amount'];

        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be positive');
        }

        if ($amount > 99_999_999.99) {
            throw new \InvalidArgumentException('amount is too large');
        }

        $type = (string) ($args['type'] ?? 'limit');

        if (!in_array($type, ['limit', 'goal'], true)) {
            throw new \InvalidArgumentException('type must be limit|goal');
        }

        if ($type === 'goal') {
            return $this->prepareCreateGoal($user, $args, round($amount, 2));
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
        $startDate = $this->planning->dateArg($args['start_date'] ?? null, 'start_date');

        if ($startDate !== null && $startDate > $user->todayDateString()) {
            throw new \InvalidArgumentException('start_date cannot be in the future');
        }

        // Default (no start_date): calendar weeks/months/years, counting from the start of the
        // current one. Legacy align_to_calendar=false means "start today".
        if ($startDate === null && array_key_exists('align_to_calendar', $args) && !filter_var($args['align_to_calendar'], FILTER_VALIDATE_BOOL)) {
            $startDate = $user->todayDateString();
        }
        $lengthEnum = BudgetLength::from($length);
        $notBefore = $startDate ?? $lengthEnum->calendarStart($user->nowInTimezone())->toDateString();

        $payload = [
            'title' => $title !== '' ? $title : null,
            'amount' => $amount,
            'length' => $length,
            'tag_ids' => $tagIds,
            'start_date' => $startDate,
        ];

        $preview = 'Create limit:' . "\n"
            . ($title ? "{$title}\n" : '')
            . UserFormatter::formatMoney($user, $amount) . " / {$length}\n"
            . 'Tags: ' . $this->tagLabels($user, $tagIds) . "\n"
            . 'Period: ' . $this->planning->limitPeriodHint($user, $lengthEnum, $lengthEnum->anchorFor($startDate), $notBefore);

        return ['payload' => $payload, 'preview' => $preview];
    }

    // ---------------------------------------------------------------------
    // Apply a confirmed mutation (writes)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    public function applyCreateTransaction(User $user, array $payload, TransactionSource $source): Transaction
    {
        $tagIds = $this->sanitizeOwnedTagIds($user, $payload['tag_ids'] ?? []);

        return $this->createTransaction->execute($user, [
            'amount' => (float) $payload['amount'],
            'at' => (string) $payload['at'],
            'note' => $payload['note'] ?? null,
            'tags' => $tagIds,
        ], $source);
    }

    /**
     * Null when the transaction no longer exists.
     *
     * @param array<string, mixed> $payload
     */
    public function applyUpdateTransaction(User $user, array $payload): ?Transaction
    {
        $id = (int) ($payload['id'] ?? 0);
        $tx = $user->transactions()->find($id);
        if (!$tx) {
            return null;
        }

        // UpdateTransactionAction re-checks ownership and keeps deleted tags the
        // transaction already carries; sanitizeOwnedTagIds() would drop them.
        return $this->updateTransaction->execute($user, $tx, [
            'amount' => (float) $payload['amount'],
            'at' => (string) $payload['at'],
            'note' => $payload['note'] ?? null,
            'tags' => is_array($payload['tag_ids'] ?? null) ? $payload['tag_ids'] : [],
        ]);
    }

    /**
     * False when the transaction no longer exists.
     *
     * @param array<string, mixed> $payload
     */
    public function applyDeleteTransaction(User $user, array $payload): bool
    {
        $id = (int) ($payload['id'] ?? 0);
        $tx = $user->transactions()->find($id);
        if (!$tx) {
            return false;
        }

        $this->deleteTransaction->execute($user, $tx);

        return true;
    }

    /**
     * Null when none of the budget's tags are still valid.
     *
     * @param array<string, mixed> $payload
     */
    public function applyCreateBudget(User $user, array $payload): ?Budget
    {
        $tagIds = $this->sanitizeOwnedTagIds($user, $payload['tag_ids'] ?? []);
        if ($tagIds === []) {
            return null;
        }

        if (($payload['type'] ?? 'limit') === 'goal') {
            return $this->createBudget->execute($user, [
                'type' => 'goal',
                'title' => $payload['title'] ?? null,
                'amount' => (float) $payload['amount'],
                'tag_ids' => $tagIds,
                'start_date' => $payload['start_date'] ?? null,
                'target_date' => $payload['target_date'] ?? null,
                'start_amount' => isset($payload['start_amount']) ? (float) $payload['start_amount'] : null,
            ]);
        }

        BudgetLength::from((string) $payload['length']);

        return $this->createBudget->execute($user, [
            'title' => $payload['title'] ?? null,
            'amount' => (float) $payload['amount'],
            'length' => (string) $payload['length'],
            'tag_ids' => $tagIds,
            // Null = calendar periods from the start of the current one (CreateBudgetAction).
            'start_date' => $payload['start_date'] ?? null,
        ]);
    }

    /**
     * Create tags (titles should already be filtered with filterNewTagTitles()).
     *
     * @param list<string> $titles
     * @return list<string> "emoji title" labels of the created tags
     */
    public function createTags(User $user, array $titles): array
    {
        $created = [];
        foreach ($titles as $title) {
            $tag = $this->createTag->execute($user, [
                'title' => $title,
                'emoji' => self::DEFAULT_TAG_EMOJI,
                'parent_id' => null,
                'calc_balance' => true,
            ]);
            $created[] = trim($tag->emoji . ' ' . $tag->title);
        }

        return $created;
    }

    /**
     * Normalize a stored rename preview back into proposals.
     *
     * @return list<array{id: int, before: string, after: string}>
     */
    public function normalizeRenameProposals(mixed $rawProposals): array
    {
        $proposals = [];
        foreach (is_array($rawProposals) ? $rawProposals : [] as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }
            $id = is_numeric($proposal['id'] ?? null) ? (int) $proposal['id'] : 0;
            $before = is_string($proposal['before'] ?? null) ? (string) $proposal['before'] : '';
            $after = is_string($proposal['after'] ?? null) ? (string) $proposal['after'] : '';
            if ($id < 1 || $after === '') {
                continue;
            }
            $proposals[] = ['id' => $id, 'before' => $before, 'after' => $after];
        }

        return $proposals;
    }

    /**
     * Apply renames atomically. Throws when a tag changed while the preview was waiting.
     *
     * @param list<array{id: int, before: string, after: string}> $proposals
     */
    public function renameTags(User $user, array $proposals): void
    {
        DB::transaction(function () use ($user, $proposals): void {
            foreach ($proposals as $proposal) {
                $tag = Tag::query()
                    ->where('user_id', $user->id)
                    ->whereKey($proposal['id'])
                    ->first();

                if (!$tag || mb_strtolower(trim($tag->title)) !== mb_strtolower(trim($proposal['before']))) {
                    throw new \RuntimeException('A tag changed while the preview was waiting.');
                }

                $this->updateTag->execute($user, $tag, [
                    'title' => $proposal['after'],
                    'emoji' => $tag->emoji,
                    'calc_balance' => $tag->calc_balance,
                    'parent_id' => $tag->parent_id,
                ]);
            }
        });
    }

    /**
     * Store the current tag list as the session's last_list (after tag writes).
     */
    public function saveTagListSession(User $user, AgentSession $session): void
    {
        $session->setDomain('tags');
        $session->setLastList($this->listTags->execute($user)->map(
            static fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'title' => (string) $tag->title,
                'emoji' => (string) $tag->emoji,
            ],
        )->values()->all());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function serializeTransaction(User $user, Transaction $tx): array
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
     * @param list<string> $titles
     * @return list<string>
     */
    public function filterNewTagTitles(User $user, array $titles): array
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
     * @param mixed $raw
     * @return list<int>
     */
    public function sanitizeOwnedTagIds(User $user, mixed $raw): array
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
    public function tagLabels(User $user, array $tagIds): string
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
     * @param list<array<string, mixed>> $rawProposals
     * @return list<array{id: int, before: string, after: string}>
     */
    private function resolveRenameProposals(User $user, AgentSession $session, array $rawProposals): array
    {
        $lastList = $session->get()['last_list'];
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

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareCreateGoal(User $user, array $args, float $amount): array
    {
        $tagIds = $this->sanitizeOwnedTagIds($user, $args['tag_ids'] ?? []);

        if ($tagIds === []) {
            throw new \InvalidArgumentException('tag_ids required (at least one owned tag: the savings tag(s) whose transactions count toward the goal)');
        }

        $title = is_string($args['title'] ?? null) ? mb_substr(trim((string) $args['title']), 0, 255) : null;
        $targetDate = $this->planning->dateArg($args['target_date'] ?? null, 'target_date');

        if ($targetDate !== null && $targetDate < $user->todayDateString()) {
            throw new \InvalidArgumentException(Budget::DEADLINE_ERROR);
        }

        $startDate = $this->planning->dateArg($args['start_date'] ?? null, 'start_date');

        if ($startDate !== null && $startDate > $user->todayDateString()) {
            throw new \InvalidArgumentException('start_date cannot be in the future');
        }

        if ($startDate !== null && $targetDate !== null && $startDate > $targetDate) {
            throw new \InvalidArgumentException('start_date must be on or before target_date');
        }

        $startAmount = null;

        if (isset($args['start_amount']) && $args['start_amount'] !== '') {
            if (!is_numeric($args['start_amount']) || (float) $args['start_amount'] < 0 || (float) $args['start_amount'] > 99_999_999.99) {
                throw new \InvalidArgumentException('start_amount must be zero or more');
            }
            $startAmount = round((float) $args['start_amount'], 2);
        }

        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);
        $preview = 'Create savings goal:' . "\n"
            . ($title ? "{$title}\n" : '')
            . 'Target: ' . $money($amount) . ($targetDate ? ' by ' . UserFormatter::formatDate($user, $targetDate) : '') . "\n"
            . ($startAmount ? 'Already saved: ' . $money($startAmount) . "\n" : '')
            . 'Tags: ' . $this->tagLabels($user, $tagIds) . "\n"
            . 'Counts transactions with these tags from ' . ($startDate && $startDate !== $user->todayDateString() ? UserFormatter::formatDate($user, $startDate) : 'today') . ': transfers into savings (expenses) add, withdrawals subtract.';

        return [
            'payload' => [
                'type' => 'goal',
                'title' => $title !== '' ? $title : null,
                'amount' => $amount,
                'tag_ids' => $tagIds,
                'start_date' => $startDate,
                'target_date' => $targetDate,
                'start_amount' => $startAmount,
            ],
            'preview' => $preview,
        ];
    }
}
