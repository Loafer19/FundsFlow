<?php

namespace App\Actions\Agent;

use App\Actions\Budgets\CalculateBudgetPeriodSpentAction;
use App\Actions\Budgets\DeleteBudgetAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\Budgets\PauseBudgetAction;
use App\Actions\Budgets\ResumeBudgetAction;
use App\Actions\Budgets\UpdateBudgetAction;
use App\Actions\RecurringTransactions\CreateRecurringTransactionAction;
use App\Actions\RecurringTransactions\DeleteRecurringTransactionAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\RecurringTransactions\UpdateRecurringTransactionAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Enums\BudgetLength;
use App\Enums\RecurringFrequency;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PeriodRange;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Planning tools shared by every agent transport: budget status / edits,
 * recurring rules and upcoming charges, period comparison.
 * Reads return plain arrays; writes are split into prepare() (validated
 * payload + human preview for Confirm/Cancel) and apply() (after Confirm),
 * always through the regular Budget / Recurring actions (same Gate checks).
 */
class AgentPlanningTools
{
    public const READ_TOOLS = ['budget_status', 'upcoming_recurring', 'compare_periods'];

    public const STAGING_TOOLS = [
        'update_budget',
        'pause_budget',
        'resume_budget',
        'delete_budget',
        'create_recurring',
        'update_recurring',
        'pause_recurring',
        'resume_recurring',
        'delete_recurring',
    ];

    public const MAX_UPCOMING_DAYS = 90;

    public const MAX_UPCOMING_ITEMS = 60;

    public const MAX_COMPARE_TAGS = 25;

    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    public function __construct(
        private readonly ListBudgetsAction $listBudgets,
        private readonly CalculateBudgetPeriodSpentAction $calculateSpent,
        private readonly UpdateBudgetAction $updateBudget,
        private readonly PauseBudgetAction $pauseBudget,
        private readonly ResumeBudgetAction $resumeBudget,
        private readonly DeleteBudgetAction $deleteBudget,
        private readonly ListRecurringTransactionsAction $listRecurring,
        private readonly CreateRecurringTransactionAction $createRecurring,
        private readonly UpdateRecurringTransactionAction $updateRecurring,
        private readonly DeleteRecurringTransactionAction $deleteRecurring,
        private readonly ListTransactionsAction $listTransactions,
        private readonly ListTagsAction $listTags,
    ) {}

    public static function isRead(string $name): bool
    {
        return in_array($name, self::READ_TOOLS, true);
    }

    public static function isStaging(string $name): bool
    {
        return in_array($name, self::STAGING_TOOLS, true);
    }

    /**
     * Tool schemas (same on every transport), reads first.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $fn = AgentToolExecutor::fn(...);
        $id = ['type' => 'integer'];
        $period = ['type' => 'string', 'enum' => PeriodRange::PRESETS];
        $date = ['type' => 'string', 'description' => 'YYYY-MM-DD'];
        $tagIds = ['type' => 'array', 'items' => ['type' => 'integer']];

        return [
            $fn('budget_status', 'Budget status for the current budget period: limit, spent, left, days left, safe daily allowance, average daily spend and projected end-of-period spend (status on_track|at_risk|over). Optional budget_id for one budget; include_paused also lists paused budgets (status paused).', [
                'type' => 'object',
                'properties' => [
                    'budget_id' => $id,
                    'include_paused' => ['type' => 'boolean'],
                ],
            ]),
            $fn('upcoming_recurring', 'Upcoming charges/income from active recurring rules in the next N days (default 7, max 90), with dates and totals.', [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_UPCOMING_DAYS],
                ],
            ]),
            $fn('compare_periods', 'Compare two periods: income, expenses, net and per-tag expense deltas. period is the current period (default this_month) compared with the previous one (previous calendar month/quarter/year/week, or the same number of days before for from/to). Optional compare_from/compare_to override the previous period; tag_ids limits to transactions with any of those tags. For running periods also returns previous_to_date (same elapsed days) for a fair comparison.', [
                'type' => 'object',
                'properties' => [
                    'period' => $period,
                    'from' => $date,
                    'to' => $date,
                    'compare_from' => $date,
                    'compare_to' => $date,
                    'tag_ids' => $tagIds,
                ],
            ]),
            $fn('update_budget', 'Propose editing a budget (limit amount, length, tags, title). Stages Confirm/Cancel. Omitted fields keep their current value. A changed limit/length/tags starts a new budget period today (history kept); align_to_calendar defaults to the budget\'s current alignment.', [
                'type' => 'object',
                'properties' => [
                    'id' => $id,
                    'title' => ['type' => 'string'],
                    'amount' => ['type' => 'number'],
                    'length' => ['type' => 'string', 'enum' => ['week', 'month', 'year']],
                    'tag_ids' => $tagIds,
                    'align_to_calendar' => ['type' => 'boolean'],
                ],
                'required' => ['id'],
            ]),
            $fn('pause_budget', 'Propose pausing an active budget (tracking stops today, history kept). Stages Confirm/Cancel.', [
                'type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'],
            ]),
            $fn('resume_budget', 'Propose resuming a paused budget with its last limit and tags, from today. Stages Confirm/Cancel.', [
                'type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'],
            ]),
            $fn('delete_budget', 'Propose deleting a budget and its history. Stages Confirm/Cancel. Prefer pause_budget when the user may want it back.', [
                'type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'],
            ]),
            $fn('create_recurring', 'Propose a recurring rule that auto-adds a transaction on a schedule. Stages Confirm/Cancel. amount is signed: negative for expenses/subscriptions, positive for income. frequency: daily|weekly|monthly|yearly. starts_at = first occurrence (default today); ends_at optional (inclusive).', [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number'],
                    'frequency' => ['type' => 'string', 'enum' => self::FREQUENCIES],
                    'starts_at' => $date,
                    'ends_at' => $date,
                    'note' => ['type' => 'string'],
                    'tag_ids' => $tagIds,
                ],
                'required' => ['amount', 'frequency'],
            ]),
            $fn('update_recurring', 'Propose editing a recurring rule by id. Stages Confirm/Cancel. Omitted fields stay unchanged; clear_ends_at removes the end date.', [
                'type' => 'object',
                'properties' => [
                    'id' => $id,
                    'amount' => ['type' => 'number'],
                    'frequency' => ['type' => 'string', 'enum' => self::FREQUENCIES],
                    'starts_at' => $date,
                    'ends_at' => $date,
                    'clear_ends_at' => ['type' => 'boolean'],
                    'note' => ['type' => 'string'],
                    'tag_ids' => $tagIds,
                ],
                'required' => ['id'],
            ]),
            $fn('pause_recurring', 'Propose pausing a recurring rule (no transactions are added while paused). Stages Confirm/Cancel.', [
                'type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'],
            ]),
            $fn('resume_recurring', 'Propose resuming a paused recurring rule from its next date on or after today (missed dates are not backfilled). Stages Confirm/Cancel.', [
                'type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'],
            ]),
            $fn('delete_recurring', 'Propose deleting a recurring rule. Already-added transactions stay. Stages Confirm/Cancel.', [
                'type' => 'object', 'properties' => ['id' => $id], 'required' => ['id'],
            ]),
        ];
    }

    /**
     * Parameters of the report tools (send_report on Telegram, show_report on web).
     *
     * @return array<string, mixed>
     */
    public static function reportParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => ['type' => 'string', 'enum' => PeriodRange::PRESETS, 'description' => 'Default this_month. Ignored when from/to given.'],
                'from' => ['type' => 'string', 'description' => 'Explicit start YYYY-MM-DD'],
                'to' => ['type' => 'string', 'description' => 'Explicit end YYYY-MM-DD'],
                'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only transactions with any of these tags'],
                'untagged' => ['type' => 'boolean', 'description' => 'Include (or, without tag_ids, only) untagged transactions'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function read(User $user, AgentSession $session, string $name, array $args): array
    {
        return match ($name) {
            'budget_status' => $this->budgetStatus($user, $session, $args),
            'upcoming_recurring' => $this->upcomingRecurring($user, $args),
            'compare_periods' => $this->comparePeriods($user, $args),
            default => throw new InvalidArgumentException("Unknown read tool: {$name}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    public function prepare(User $user, string $name, array $args): array
    {
        return match ($name) {
            'update_budget' => $this->prepareUpdateBudget($user, $args),
            'pause_budget' => $this->prepareBudgetState($user, $args, 'pause'),
            'resume_budget' => $this->prepareBudgetState($user, $args, 'resume'),
            'delete_budget' => $this->prepareBudgetState($user, $args, 'delete'),
            'create_recurring' => $this->prepareCreateRecurring($user, $args),
            'update_recurring' => $this->prepareUpdateRecurring($user, $args),
            'pause_recurring' => $this->prepareRecurringState($user, $args, 'pause'),
            'resume_recurring' => $this->prepareRecurringState($user, $args, 'resume'),
            'delete_recurring' => $this->prepareRecurringState($user, $args, 'delete'),
            default => throw new InvalidArgumentException("Unknown staging tool: {$name}"),
        };
    }

    /**
     * Apply a confirmed change. Never throws for "gone" records; real failures bubble up.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, summary: string}
     */
    public function apply(User $user, string $action, array $payload): array
    {
        return match ($action) {
            'update_budget', 'pause_budget', 'resume_budget', 'delete_budget' => $this->applyBudget($user, $action, $payload),
            'create_recurring', 'update_recurring', 'pause_recurring', 'resume_recurring', 'delete_recurring' => $this->applyRecurring($user, $action, $payload),
            default => ['ok' => false, 'message' => 'Nothing to confirm.', 'summary' => 'Nothing to confirm.'],
        };
    }

    // ---------------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function budgetStatus(User $user, AgentSession $session, array $args): array
    {
        $onlyId = is_numeric($args['budget_id'] ?? null) ? (int) $args['budget_id'] : null;
        $includePaused = filter_var($args['include_paused'] ?? false, FILTER_VALIDATE_BOOL) || $onlyId !== null;
        $now = $user->nowInTimezone();
        $items = [];
        $list = [];

        foreach ($this->listBudgets->execute($user) as $budget) {
            if ($onlyId !== null && (int) $budget->id !== $onlyId) {
                continue;
            }
            $open = $budget->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);
            if (!$open && !$includePaused) {
                continue;
            }
            $period = $open ?? $budget->periods->sortByDesc(fn (BudgetPeriod $p) => $p->starts_at?->toDateString())->first();
            if (!$period) {
                continue;
            }

            $label = $this->budgetLabel($budget, $period);
            $list[] = ['id' => (int) $budget->id, 'title' => $label];
            $items[] = $open ? $this->budgetPeriodStatus($user, $budget, $open, $now, $label) : [
                'id' => (int) $budget->id,
                'title' => $label,
                'status' => 'paused',
                'amount_limit' => (float) $period->amount,
                'amount_limit_formatted' => UserFormatter::formatMoney($user, (float) $period->amount),
                'length' => $period->length->value,
                'paused_since' => $period->ends_at?->toDateString(),
                'tag_ids' => $this->periodTagIds($period),
            ];
        }

        $session->setDomain('budgets');
        $session->setLastList($list);

        if ($onlyId !== null && $items === []) {
            return ['error' => 'Budget not found.', 'count' => 0, 'budgets' => []];
        }

        return [
            'today' => $now->toDateString(),
            'count' => count($items),
            'budgets' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function budgetPeriodStatus(User $user, Budget $budget, BudgetPeriod $period, Carbon $now, ?string $label = null): array
    {
        $today = $now->copy()->startOfDay();
        $start = $this->calculateSpent->currentBucketStart($period, $now)->copy()->startOfDay();
        $end = match ($period->length) {
            BudgetLength::Week => $today->copy()->endOfWeek()->startOfDay(),
            BudgetLength::Month => $today->copy()->endOfMonth()->startOfDay(),
            BudgetLength::Year => $today->copy()->endOfYear()->startOfDay(),
        };

        // Whole calendar days from date strings: starts_at and "today" can carry different timezones.
        $day = fn (Carbon $d): int => intdiv((int) strtotime($d->toDateString() . ' 00:00:00 UTC'), 86400);
        $totalDays = max(1, $day($end) - $day($start) + 1);
        $elapsed = max(1, min($totalDays, $day($today) - $day($start) + 1));
        $daysLeft = max(1, $totalDays - $elapsed + 1); // today counts as a day left to spend

        $limit = (float) $period->amount;
        $spent = round($this->calculateSpent->execute($period, $user, $now), 2) + 0.0; // + 0.0 turns -0.0 into 0.0
        $left = round($limit - $spent, 2);
        $perDay = $left > 0 ? round($left / $daysLeft, 2) : 0.0;
        $avgDaily = round($spent / $elapsed, 2);
        $projected = round($spent / $elapsed * $totalDays, 2);
        $status = $spent >= $limit && $limit > 0 ? 'over' : ($projected > $limit ? 'at_risk' : 'on_track');
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);

        return [
            'id' => (int) $budget->id,
            'title' => $label ?? $this->budgetLabel($budget, $period),
            'status' => $status,
            'length' => $period->length->value,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'days_total' => $totalDays,
            'days_elapsed' => $elapsed,
            'days_left' => $daysLeft,
            'amount_limit' => $limit,
            'amount_limit_formatted' => $money($limit),
            'spent' => $spent,
            'spent_formatted' => $money($spent),
            'left' => $left,
            'left_formatted' => $money($left),
            'percent' => $limit > 0 ? (int) round($spent / $limit * 100) : 0,
            'per_day_allowance' => $perDay,
            'per_day_allowance_formatted' => $money($perDay),
            'avg_daily_spend' => $avgDaily,
            'avg_daily_spend_formatted' => $money($avgDaily),
            'projected_spend' => $projected,
            'projected_spend_formatted' => $money($projected),
            'projected_over_by' => max(0.0, round($projected - $limit, 2)),
            'tag_ids' => $this->periodTagIds($period),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function upcomingRecurring(User $user, array $args): array
    {
        $days = is_numeric($args['days'] ?? null) ? max(1, min(self::MAX_UPCOMING_DAYS, (int) $args['days'])) : 7;
        $today = PeriodRange::today($user);
        $horizon = $today->addDays($days);

        $items = $this->occurrencesBetween($user, $today, $horizon);
        $truncated = count($items) > self::MAX_UPCOMING_ITEMS;
        $items = array_slice($items, 0, self::MAX_UPCOMING_ITEMS);

        $expenses = 0.0;
        $income = 0.0;
        foreach ($items as $item) {
            if ($item['amount'] < 0) {
                $expenses += $item['amount'];
            } else {
                $income += $item['amount'];
            }
        }

        return [
            'from' => $today->toDateString(),
            'to' => $horizon->toDateString(),
            'days' => $days,
            'count' => count($items),
            'truncated' => $truncated,
            'total_expenses' => round($expenses, 2),
            'total_expenses_formatted' => UserFormatter::formatMoney($user, $expenses),
            'total_income' => round($income, 2),
            'total_income_formatted' => UserFormatter::formatMoney($user, $income),
            'items' => $items,
        ];
    }

    /**
     * Occurrences of active rules with from <= date <= to, sorted by date.
     * Dates already due but not yet materialized (before the daily run) count from today.
     *
     * @return list<array<string, mixed>>
     */
    public function occurrencesBetween(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tags = $this->listTags->execute($user)->keyBy('id');
        $items = [];

        foreach ($this->listRecurring->execute($user) as $rule) {
            if (!$rule->active || !$rule->starts_at) {
                continue;
            }
            $cursor = $from;
            if ($rule->next_run_at && $rule->next_run_at->toDateString() > $cursor->toDateString()) {
                $cursor = CarbonImmutable::parse($rule->next_run_at->toDateString());
            }
            $date = $rule->frequency->firstOnOrAfter($rule->starts_at, $cursor);
            $guard = 0;

            while ($date->lte($to) && $guard++ < 400) {
                if ($rule->ends_at && $date->toDateString() > $rule->ends_at->toDateString()) {
                    break;
                }
                $items[] = [
                    'date' => $date->toDateString(),
                    'rule_id' => (int) $rule->id,
                    'amount' => (float) $rule->amount,
                    'amount_formatted' => UserFormatter::formatMoney($user, (float) $rule->amount),
                    'note' => (string) ($rule->note ?? ''),
                    'frequency' => $rule->frequency->value,
                    'tags' => $rule->tags->map(fn (Tag $t) => trim($t->emoji . ' ' . $t->title))->filter()->values()->all(),
                ];
                $date = $rule->frequency->firstOnOrAfter($rule->starts_at, $date->addDay());
            }
        }

        usort($items, fn (array $a, array $b) => [$a['date'], $a['rule_id']] <=> [$b['date'], $b['rule_id']]);

        return $items;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function comparePeriods(User $user, array $args): array
    {
        $range = PeriodRange::resolve($user, $args);
        $explicitPrev = PeriodRange::isDate($args['compare_from'] ?? null) && PeriodRange::isDate($args['compare_to'] ?? null);
        $previous = $explicitPrev
            ? PeriodRange::explicit(CarbonImmutable::parse($args['compare_from'], $user->timezone()), CarbonImmutable::parse($args['compare_to'], $user->timezone()), $range->today, 'Comparison period')
            : null;
        $prevFrom = $previous?->from ?? $range->previousFrom;
        $prevTo = $previous?->to ?? $range->previousTo;
        $prevLabel = $previous?->label ?? $range->previousLabel;

        $tagFilter = $this->ownedTagIds($user, $args['tag_ids'] ?? []);
        $all = $this->listTransactions->execute($user);
        if ($tagFilter !== []) {
            $all = $all->filter(fn (Transaction $tx) => $tx->tags->pluck('id')->intersect($tagFilter)->isNotEmpty());
        }

        $current = $this->between($all, $range->from, $range->to);
        $before = $this->between($all, $prevFrom, $prevTo);
        $cur = $this->totals($user, $current);
        $prev = $this->totals($user, $before);

        $curTags = $this->expenseByTag($current);
        $prevTags = $this->expenseByTag($before);
        $rows = [];
        foreach (array_unique(array_merge(array_keys($curTags), array_keys($prevTags))) as $key) {
            $meta = $curTags[$key] ?? $prevTags[$key];
            $now = $curTags[$key]['amount'] ?? 0.0;
            $was = $prevTags[$key]['amount'] ?? 0.0;
            $rows[] = [
                'tag_id' => $meta['id'],
                'tag' => $meta['label'],
                'current' => round($now, 2),
                'previous' => round($was, 2),
                'delta' => round($now - $was, 2),
                'delta_formatted' => ($now - $was >= 0 ? '+' : '') . UserFormatter::formatMoney($user, $now - $was),
                'delta_percent' => $was > 0 ? (int) round(($now - $was) / $was * 100) : null,
            ];
        }
        usort($rows, fn (array $a, array $b) => abs($b['delta']) <=> abs($a['delta']));

        $out = [
            'current' => ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'label' => $range->label, 'partial' => $range->isPartial(), 'days' => $range->days()] + $cur,
            'previous' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString(), 'label' => $prevLabel] + $prev,
            'expense_change' => $this->change($user, abs($cur['expenses']), abs($prev['expenses'])),
            'income_change' => $this->change($user, $cur['income'], $prev['income']),
            'tags' => array_slice($rows, 0, self::MAX_COMPARE_TAGS),
            'tags_truncated' => count($rows) > self::MAX_COMPARE_TAGS,
            'note' => 'Expense amounts per tag are positive numbers; a transaction with several tags counts under each tag.',
        ];
        if ($tagFilter !== []) {
            $out['tag_filter'] = $tagFilter;
        }

        if (!$explicitPrev && $range->isPartial()) {
            $cut = $range->previousToDate();
            $out['previous_to_date'] = ['from' => $prevFrom->toDateString(), 'to' => $cut->toDateString()]
                + $this->totals($user, $this->between($all, $prevFrom, $cut));
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Budget writes
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareUpdateBudget(User $user, array $args): array
    {
        $budget = $this->findBudget($user, $args['id'] ?? null);
        $current = $budget->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);
        $base = $current ?? $budget->periods->sortByDesc(fn (BudgetPeriod $p) => $p->starts_at?->toDateString())->first();
        if (!$base) {
            throw new InvalidArgumentException('budget has no periods');
        }

        $amount = (float) $base->amount;
        if (array_key_exists('amount', $args) && $args['amount'] !== null) {
            if (!is_numeric($args['amount']) || (float) $args['amount'] < 0.01) {
                throw new InvalidArgumentException('amount must be a positive number');
            }
            $amount = round((float) $args['amount'], 2);
        }

        $length = $base->length->value;
        if (isset($args['length'])) {
            if (!in_array($args['length'], ['week', 'month', 'year'], true)) {
                throw new InvalidArgumentException('length must be week|month|year');
            }
            $length = (string) $args['length'];
        }

        $baseTagIds = $this->periodTagIds($base);
        $tagIds = $baseTagIds;
        if (is_array($args['tag_ids'] ?? null)) {
            // Keep tags the budget already has (even if since deleted); new ones must be owned.
            $owned = $this->ownedTagIds($user, $args['tag_ids']);
            $kept = array_values(array_intersect(array_map('intval', array_filter($args['tag_ids'], 'is_numeric')), $baseTagIds));
            $tagIds = array_values(array_unique(array_merge($owned, $kept)));
            if ($tagIds === []) {
                throw new InvalidArgumentException('tag_ids must contain at least one of the user\'s tags');
            }
        }
        sort($tagIds);
        $sortedBase = $baseTagIds;
        sort($sortedBase);

        $title = $budget->title;
        $titleChanged = false;
        if (array_key_exists('title', $args) && is_string($args['title'])) {
            $new = mb_substr(trim($args['title']), 0, 255);
            $new = $new === '' ? null : $new;
            $titleChanged = $new !== $budget->title;
            $title = $new;
        }

        $alignedNow = $base->starts_at
            && $base->starts_at->toDateString() === $base->length->calendarStart($base->starts_at->copy())->toDateString();
        $align = array_key_exists('align_to_calendar', $args)
            ? filter_var($args['align_to_calendar'], FILTER_VALIDATE_BOOL)
            : $alignedNow;

        $periodChanged = abs($amount - (float) $base->amount) > 0.001 || $length !== $base->length->value || $tagIds !== $sortedBase;
        if (!$periodChanged && !$titleChanged && $current) {
            throw new InvalidArgumentException('nothing to change: values equal the current budget');
        }

        $label = $this->budgetLabel($budget, $base);
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);
        $lines = ["Update budget “{$label}”:"];
        if ($titleChanged) {
            $lines[] = 'Title: ' . ($budget->title ?: '—') . ' → ' . ($title ?: '—');
        }
        $lines[] = abs($amount - (float) $base->amount) > 0.001
            ? 'Limit: ' . $money((float) $base->amount) . ' → ' . $money($amount)
            : 'Limit: ' . $money($amount);
        $lines[] = $length !== $base->length->value ? "Length: {$base->length->value} → {$length}" : "Length: {$length}";
        $lines[] = $tagIds !== $sortedBase
            ? 'Tags: ' . $this->tagLabels($user, $baseTagIds) . ' → ' . $this->tagLabels($user, $tagIds)
            : 'Tags: ' . $this->tagLabels($user, $tagIds);
        if (!$current) {
            $lines[] = 'This budget is paused; saving resumes it from today.';
        } elseif ($periodChanged) {
            $lines[] = $align
                ? 'Starts a new period aligned to the calendar ' . $length . '; history is kept.'
                : 'Starts a new period today; history is kept.';
        }

        return [
            'payload' => [
                'id' => (int) $budget->id,
                'title' => $title,
                'title_changed' => $titleChanged,
                'amount' => $amount,
                'length' => $length,
                'tag_ids' => $tagIds,
                'align_to_calendar' => $align,
            ],
            'preview' => implode("\n", $lines),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareBudgetState(User $user, array $args, string $op): array
    {
        $budget = $this->findBudget($user, $args['id'] ?? null);
        $current = $budget->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);
        $base = $current ?? $budget->periods->sortByDesc(fn (BudgetPeriod $p) => $p->starts_at?->toDateString())->first();
        $label = $base ? $this->budgetLabel($budget, $base) : ($budget->title ?: 'Budget #' . $budget->id);
        $terms = $base ? UserFormatter::formatMoney($user, (float) $base->amount) . ' / ' . $base->length->value : '';

        if ($op === 'pause' && !$current) {
            throw new InvalidArgumentException('budget is already paused');
        }
        if ($op === 'resume' && $current) {
            throw new InvalidArgumentException('budget is already active');
        }
        if ($op === 'resume' && !$base) {
            throw new InvalidArgumentException('budget has no periods to resume');
        }

        $preview = match ($op) {
            'pause' => "Pause budget “{$label}” ({$terms}).\nTracking stops today; history is kept. You can resume it later.",
            'resume' => "Resume budget “{$label}” ({$terms}) from today.\nTags: " . $this->tagLabels($user, $this->periodTagIds($base)),
            default => "Delete budget “{$label}”" . ($terms !== '' ? " ({$terms})" : '') . " and all its history.\nThis can't be undone (pause keeps history instead).",
        };

        return ['payload' => ['id' => (int) $budget->id], 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, summary: string}
     */
    private function applyBudget(User $user, string $action, array $payload): array
    {
        $budget = $user->budgets()->with('periods.tags')->find((int) ($payload['id'] ?? 0));
        if (!$budget) {
            return ['ok' => false, 'message' => 'That budget no longer exists.', 'summary' => 'Budget to change no longer exists.'];
        }
        $label = $budget->title ?: ('Budget #' . $budget->id);

        switch ($action) {
            case 'update_budget':
                $data = [
                    'amount' => (float) $payload['amount'],
                    'length' => BudgetLength::from((string) $payload['length'])->value,
                    'tag_ids' => array_map('intval', (array) ($payload['tag_ids'] ?? [])),
                    'align_to_calendar' => (bool) ($payload['align_to_calendar'] ?? false),
                ];
                if (!empty($payload['title_changed'])) {
                    $data['title'] = $payload['title'] ?? null;
                }
                $budget = $this->updateBudget->execute($user, $budget, $data);
                $label = $budget->title ?: ('Budget #' . $budget->id);

                return ['ok' => true, 'message' => '✅ Budget updated: ' . $label, 'summary' => 'Budget updated via agent confirm.'];
            case 'pause_budget':
                $this->pauseBudget->execute($user, $budget);

                return ['ok' => true, 'message' => '⏸ Budget paused: ' . $label, 'summary' => 'Budget paused via agent confirm.'];
            case 'resume_budget':
                $this->resumeBudget->execute($user, $budget);

                return ['ok' => true, 'message' => '▶️ Budget resumed: ' . $label, 'summary' => 'Budget resumed via agent confirm.'];
            default:
                $this->deleteBudget->execute($user, $budget);

                return ['ok' => true, 'message' => '🗑 Budget deleted: ' . $label, 'summary' => 'Budget deleted via agent confirm.'];
        }
    }

    // ---------------------------------------------------------------------
    // Recurring writes
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareCreateRecurring(User $user, array $args): array
    {
        $amount = $this->signedAmount($args['amount'] ?? null);
        $frequency = $this->frequency($args['frequency'] ?? null);
        $today = PeriodRange::today($user);
        $startsAt = $this->dateArg($args['starts_at'] ?? null, 'starts_at') ?? $today->toDateString();
        $endsAt = $this->dateArg($args['ends_at'] ?? null, 'ends_at');
        if ($endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidArgumentException('ends_at must be on or after starts_at');
        }
        $note = is_string($args['note'] ?? null) ? mb_substr(trim($args['note']), 0, 255) : '';
        $tagIds = $this->ownedTagIds($user, $args['tag_ids'] ?? []);

        $lines = [
            'Create recurring rule:',
            UserFormatter::formatMoney($user, $amount) . ' · ' . $frequency . ' · from ' . $startsAt . ($endsAt ? ' until ' . $endsAt : ''),
        ];
        if ($note !== '') {
            $lines[] = 'Note: ' . $note;
        }
        $lines[] = 'Tags: ' . ($tagIds !== [] ? $this->tagLabels($user, $tagIds) : '—');
        if ($startsAt < $today->toDateString()) {
            $missed = $this->countOccurrences(RecurringFrequency::from($frequency), $startsAt, $endsAt, $today->subDay()->toDateString());
            $lines[] = "⚠️ Start is in the past: {$missed} past occurrence(s) will be added automatically by the next daily run.";
        } else {
            $lines[] = 'First transaction: ' . $startsAt . ($startsAt === $today->toDateString() ? ' (added by the next daily run)' : '');
        }

        return [
            'payload' => [
                'amount' => $amount,
                'frequency' => $frequency,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'note' => $note !== '' ? $note : null,
                'tag_ids' => $tagIds,
            ],
            'preview' => implode("\n", $lines),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareUpdateRecurring(User $user, array $args): array
    {
        $rule = $this->findRule($user, $args['id'] ?? null);
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);
        $changes = [];
        $lines = ['Update recurring rule “' . $this->ruleLabel($user, $rule) . '”:'];

        if (array_key_exists('amount', $args) && $args['amount'] !== null) {
            $amount = $this->signedAmount($args['amount']);
            if (abs($amount - (float) $rule->amount) > 0.001) {
                $changes['amount'] = $amount;
                $lines[] = 'Amount: ' . $money((float) $rule->amount) . ' → ' . $money($amount);
            }
        }
        if (isset($args['frequency'])) {
            $frequency = $this->frequency($args['frequency']);
            if ($frequency !== $rule->frequency->value) {
                $changes['frequency'] = $frequency;
                $lines[] = "Frequency: {$rule->frequency->value} → {$frequency}";
            }
        }
        $startsAt = $this->dateArg($args['starts_at'] ?? null, 'starts_at');
        if ($startsAt !== null && $startsAt !== $rule->starts_at->toDateString()) {
            $changes['starts_at'] = $startsAt;
            $lines[] = 'Start: ' . $rule->starts_at->toDateString() . ' → ' . $startsAt;
        }
        if (filter_var($args['clear_ends_at'] ?? false, FILTER_VALIDATE_BOOL)) {
            if ($rule->ends_at) {
                $changes['ends_at'] = null;
                $lines[] = 'End date: ' . $rule->ends_at->toDateString() . ' → none';
            }
        } else {
            $endsAt = $this->dateArg($args['ends_at'] ?? null, 'ends_at');
            if ($endsAt !== null && $endsAt !== $rule->ends_at?->toDateString()) {
                $changes['ends_at'] = $endsAt;
                $lines[] = 'End date: ' . ($rule->ends_at?->toDateString() ?? 'none') . ' → ' . $endsAt;
            }
        }
        $effectiveStart = $changes['starts_at'] ?? $rule->starts_at->toDateString();
        $effectiveEnd = array_key_exists('ends_at', $changes) ? $changes['ends_at'] : $rule->ends_at?->toDateString();
        if ($effectiveEnd !== null && $effectiveEnd < $effectiveStart) {
            throw new InvalidArgumentException('ends_at must be on or after starts_at');
        }
        if (array_key_exists('note', $args) && is_string($args['note'])) {
            $note = mb_substr(trim($args['note']), 0, 255);
            if ($note !== (string) ($rule->note ?? '')) {
                $changes['note'] = $note !== '' ? $note : null;
                $lines[] = 'Note: ' . ($rule->note ?: '—') . ' → ' . ($note !== '' ? $note : '—');
            }
        }
        if (is_array($args['tag_ids'] ?? null)) {
            $current = $rule->tags->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $kept = array_values(array_intersect(array_map('intval', array_filter($args['tag_ids'], 'is_numeric')), $current));
            $new = array_values(array_unique(array_merge($this->ownedTagIds($user, $args['tag_ids']), $kept)));
            sort($new);
            if ($new !== $current) {
                $changes['tags'] = $new;
                $lines[] = 'Tags: ' . ($current ? $this->tagLabels($user, $current) : '—') . ' → ' . ($new ? $this->tagLabels($user, $new) : '—');
            }
        }

        if ($changes === []) {
            throw new InvalidArgumentException('nothing to change: values equal the current rule');
        }
        if (isset($changes['starts_at']) || isset($changes['frequency'])) {
            $lines[] = 'Next transaction: ' . $this->nextRunAfterUpdate($user, $rule, $changes);
        }

        return ['payload' => ['id' => (int) $rule->id, 'changes' => $changes], 'preview' => implode("\n", $lines)];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareRecurringState(User $user, array $args, string $op): array
    {
        $rule = $this->findRule($user, $args['id'] ?? null);
        $label = $this->ruleLabel($user, $rule);
        $terms = UserFormatter::formatMoney($user, (float) $rule->amount) . ' · ' . $rule->frequency->value;

        if ($op === 'pause' && !$rule->active) {
            throw new InvalidArgumentException('rule is already paused');
        }
        if ($op === 'resume' && $rule->active) {
            throw new InvalidArgumentException('rule is already active');
        }

        $preview = match ($op) {
            'pause' => "Pause recurring rule “{$label}” ({$terms}).\nNo transactions are added while paused.",
            'resume' => "Resume recurring rule “{$label}” ({$terms}).\nNext transaction: "
                . $rule->frequency->firstOnOrAfter($rule->starts_at, $user->nowInTimezone())->toDateString()
                . ' (dates missed while paused are not added).',
            default => "Delete recurring rule “{$label}” ({$terms}).\nTransactions it already added stay.",
        };

        return ['payload' => ['id' => (int) $rule->id], 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, summary: string}
     */
    private function applyRecurring(User $user, string $action, array $payload): array
    {
        if ($action === 'create_recurring') {
            $endsAt = $payload['ends_at'] ?? null;
            $rule = $this->createRecurring->execute($user, array_filter([
                'amount' => (float) $payload['amount'],
                'frequency' => RecurringFrequency::from((string) $payload['frequency'])->value,
                'starts_at' => (string) $payload['starts_at'],
                'ends_at' => is_string($endsAt) ? $endsAt : null,
                'note' => $payload['note'] ?? null,
                'tags' => $this->ownedTagIds($user, $payload['tag_ids'] ?? []),
            ], fn ($v, $k) => $k !== 'ends_at' || $v !== null, ARRAY_FILTER_USE_BOTH));

            return ['ok' => true, 'message' => '✅ Recurring rule created: ' . $this->ruleLabel($user, $rule), 'summary' => 'Recurring rule created via agent confirm.'];
        }

        $rule = $user->recurringTransactions()->with('tags')->find((int) ($payload['id'] ?? 0));
        if (!$rule) {
            return ['ok' => false, 'message' => 'That recurring rule no longer exists.', 'summary' => 'Recurring rule to change no longer exists.'];
        }
        $label = $this->ruleLabel($user, $rule);

        switch ($action) {
            case 'update_recurring':
                $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
                $allowed = array_intersect_key($changes, array_flip(['amount', 'frequency', 'starts_at', 'ends_at', 'note', 'tags']));
                if (isset($allowed['frequency'])) {
                    $allowed['frequency'] = RecurringFrequency::from((string) $allowed['frequency'])->value;
                }
                $rule = $this->updateRecurring->execute($user, $rule, $allowed);

                return ['ok' => true, 'message' => '✅ Recurring rule updated: ' . $this->ruleLabel($user, $rule), 'summary' => 'Recurring rule updated via agent confirm.'];
            case 'pause_recurring':
                $this->updateRecurring->execute($user, $rule, ['active' => false]);

                return ['ok' => true, 'message' => '⏸ Recurring rule paused: ' . $label, 'summary' => 'Recurring rule paused via agent confirm.'];
            case 'resume_recurring':
                $rule = $this->updateRecurring->execute($user, $rule, ['active' => true]);

                return ['ok' => true, 'message' => '▶️ Recurring rule resumed: ' . $label . ' · next ' . $rule->next_run_at?->toDateString(), 'summary' => 'Recurring rule resumed via agent confirm.'];
            default:
                $this->deleteRecurring->execute($user, $rule);

                return ['ok' => true, 'message' => '🗑 Recurring rule deleted: ' . $label, 'summary' => 'Recurring rule deleted via agent confirm.'];
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function findBudget(User $user, mixed $id): Budget
    {
        $budget = is_numeric($id) ? $user->budgets()->with('periods.tags')->find((int) $id) : null;
        if (!$budget) {
            throw new InvalidArgumentException('budget not found (use list_budgets or budget_status for ids)');
        }

        return $budget;
    }

    private function findRule(User $user, mixed $id): RecurringTransaction
    {
        $rule = is_numeric($id) ? $user->recurringTransactions()->with('tags')->find((int) $id) : null;
        if (!$rule) {
            throw new InvalidArgumentException('recurring rule not found (use list_recurring for ids)');
        }

        return $rule;
    }

    private function budgetLabel(Budget $budget, BudgetPeriod $period): string
    {
        return $budget->title
            ?: $period->tags->map(fn (Tag $tag) => trim($tag->emoji . ' ' . $tag->title))->filter()->implode(', ')
            ?: 'Budget #' . $budget->id;
    }

    private function ruleLabel(User $user, RecurringTransaction $rule): string
    {
        $rule->loadMissing('tags');
        $name = trim((string) ($rule->note ?? ''))
            ?: $rule->tags->map(fn (Tag $t) => trim($t->emoji . ' ' . $t->title))->filter()->implode(', ')
            ?: 'Rule #' . $rule->id;

        return $name . ' (' . UserFormatter::formatMoney($user, (float) $rule->amount) . ' ' . $rule->frequency->value . ')';
    }

    /**
     * @return list<int>
     */
    private function periodTagIds(BudgetPeriod $period): array
    {
        return $period->tags->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return list<int>
     */
    public function ownedTagIds(User $user, mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $owned = array_fill_keys($this->listTags->execute($user)->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $ids = [];
        foreach ($raw as $id) {
            if (is_numeric($id) && isset($owned[(int) $id])) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     */
    public function tagLabels(User $user, array $ids): string
    {
        $tags = $this->listTags->execute($user)->keyBy('id');
        $labels = [];
        foreach ($ids as $id) {
            $tag = $tags->get($id);
            $labels[] = $tag ? trim($tag->emoji . ' ' . $tag->title) : "#{$id}";
        }

        return $labels !== [] ? implode(', ', $labels) : '—';
    }

    private function signedAmount(mixed $raw): float
    {
        if (!is_numeric($raw) || abs((float) $raw) < 0.005) {
            throw new InvalidArgumentException('amount must be a non-zero number (negative = expense, positive = income)');
        }
        if (abs((float) $raw) > 1e12) {
            throw new InvalidArgumentException('amount is too large');
        }

        return round((float) $raw, 2);
    }

    private function frequency(mixed $raw): string
    {
        if (!is_string($raw) || !in_array($raw, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('frequency must be daily|weekly|monthly|yearly');
        }

        return $raw;
    }

    private function dateArg(mixed $raw, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!PeriodRange::isDate($raw)) {
            throw new InvalidArgumentException("{$field} must be YYYY-MM-DD");
        }

        return (string) $raw;
    }

    private function countOccurrences(RecurringFrequency $frequency, string $startsAt, ?string $endsAt, string $until): int
    {
        $anchor = CarbonImmutable::parse($startsAt);
        $limit = $endsAt !== null && $endsAt < $until ? $endsAt : $until;
        $n = 0;
        while ($n < 1000 && $frequency->occurrence($anchor, $n)->toDateString() <= $limit) {
            $n++;
        }

        return $n;
    }

    /**
     * Mirrors UpdateRecurringTransactionAction's next_run_at rule (for the preview only).
     *
     * @param array<string, mixed> $changes
     */
    private function nextRunAfterUpdate(User $user, RecurringTransaction $rule, array $changes): string
    {
        $startsAt = CarbonImmutable::parse($changes['starts_at'] ?? $rule->starts_at->toDateString());
        $frequency = isset($changes['frequency']) ? RecurringFrequency::from($changes['frequency']) : $rule->frequency;
        $neverRan = $rule->next_run_at->toDateString() === $rule->starts_at->toDateString();

        return $neverRan
            ? $startsAt->toDateString()
            : $frequency->firstOnOrAfter($startsAt, $user->nowInTimezone())->toDateString();
    }

    /**
     * @param iterable<Transaction> $all
     * @return Collection<int, Transaction>
     */
    private function between(iterable $all, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $a = $from->toDateString();
        $b = $to->toDateString();

        return collect($all)->filter(function (Transaction $tx) use ($a, $b): bool {
            $d = $tx->at?->format('Y-m-d');

            return $d !== null && $d >= $a && $d <= $b;
        })->values();
    }

    /**
     * @param Collection<int, Transaction> $txs
     * @return array{income: float, expenses: float, net: float, income_formatted: string, expenses_formatted: string, net_formatted: string, transaction_count: int}
     */
    private function totals(User $user, Collection $txs): array
    {
        $income = round((float) $txs->filter(fn (Transaction $tx) => $tx->amount > 0)->sum('amount'), 2);
        $expenses = round((float) $txs->filter(fn (Transaction $tx) => $tx->amount < 0)->sum('amount'), 2);

        return [
            'income' => $income,
            'income_formatted' => UserFormatter::formatMoney($user, $income),
            'expenses' => $expenses,
            'expenses_formatted' => UserFormatter::formatMoney($user, $expenses),
            'net' => round($income + $expenses, 2),
            'net_formatted' => UserFormatter::formatMoney($user, $income + $expenses),
            'transaction_count' => $txs->count(),
        ];
    }

    /**
     * @return array{delta: float, delta_formatted: string, percent: int|null}
     */
    private function change(User $user, float $now, float $was): array
    {
        $delta = round($now - $was, 2);

        return [
            'delta' => $delta,
            'delta_formatted' => ($delta >= 0 ? '+' : '') . UserFormatter::formatMoney($user, $delta),
            'percent' => abs($was) > 0.005 ? (int) round($delta / abs($was) * 100) : null,
        ];
    }

    /**
     * @param Collection<int, Transaction> $txs
     * @return array<int|string, array{id: int|null, label: string, amount: float}>
     */
    private function expenseByTag(Collection $txs): array
    {
        $out = [];
        foreach ($txs as $tx) {
            if ($tx->amount >= 0) {
                continue;
            }
            $amount = abs((float) $tx->amount);
            if ($tx->tags->isEmpty()) {
                $out['untagged'] ??= ['id' => null, 'label' => 'Untagged', 'amount' => 0.0];
                $out['untagged']['amount'] += $amount;
                continue;
            }
            foreach ($tx->tags as $tag) {
                $out[$tag->id] ??= ['id' => (int) $tag->id, 'label' => trim($tag->emoji . ' ' . $tag->title), 'amount' => 0.0];
                $out[$tag->id]['amount'] += $amount;
            }
        }

        return $out;
    }
}
