<?php

namespace App\Actions\Notifications;

use App\Actions\Budgets\CalculateGoalProgressAction;
use App\Actions\Budgets\CalculateLimitStatusAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Channels\Telegram\TelegramClient;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Support\GoalSummary;
use App\Support\UserFormatter;
use Carbon\Carbon;

class SendWeeklyDigestAction
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly ListBudgetsAction $listBudgets,
        private readonly CalculateLimitStatusAction $limitStatus,
        private readonly CalculateGoalProgressAction $goalProgress,
    ) {}

    public function execute(): void
    {
        $users = User::query()->has('transactions')->with('identities')->get();

        foreach ($users as $user) {
            $identity = $user->identities->firstWhere('provider', 'telegram');

            if (!$identity || ($identity->meta['muted'] ?? false)) {
                continue;
            }

            $now = $user->nowInTimezone();
            $start = $now->copy()->subWeek()->startOfWeek();
            $end = $now->copy()->subWeek()->endOfWeek();
            $previousStart = $now->copy()->subWeeks(2)->startOfWeek();
            $previousEnd = $now->copy()->subWeeks(2)->endOfWeek();

            $current = $this->summarize($user, $start, $end);

            if ($current['count'] === 0) {
                continue;
            }

            $previous = $this->summarize($user, $previousStart, $previousEnd);

            $message = $this->formatMessage($user, $start, $end, $current, $previous);
            $budgets = $this->budgetLines($user);

            $this->client->sendMessage(
                $identity->external_id,
                $budgets === [] ? $message : $message . "\n\n" . implode("\n", $budgets),
            );
        }
    }

    /**
     * Current state of active limits and goals, e.g.
     *   💰 Limits
     *   Groceries — ₴7,200 / ₴10,000 (72%) · 5 days left
     *   🎯 Goals
     *   Vacation — ₴12,000 / ₴50,000 (24%) · behind
     *
     * @return list<string>
     */
    public function budgetLines(User $user): array
    {
        $limits = [];
        $goals = [];
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);

        foreach ($this->listBudgets->execute($user) as $budget) {
            $period = $budget->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);

            if (!$period) {
                continue;
            }

            $label = $budget->label($period);

            if ($budget->isGoal()) {
                $g = $this->goalProgress->execute($budget, $user);
                $goals[] = $label . ' — ' . $money($g['saved']) . ' / ' . $money($g['target'])
                    . ' (' . $g['percent'] . '%) · ' . GoalSummary::statusLabel($g['status']);

                continue;
            }

            $st = $this->limitStatus->execute($period, $user);
            $tail = match ($st['status']) {
                'over' => $st['left'] < 0 ? 'over by ' . $money(-$st['left']) : 'limit reached',
                default => $st['days_left'] === 1 ? 'last day' : $st['days_left'] . ' days left',
            };
            $limits[] = ($st['status'] === 'over' ? '🔴 ' : ($st['status'] === 'at_risk' ? '🟠 ' : ''))
                . $label . ' — ' . $money($st['spent']) . ' / ' . $money($st['limit'])
                . ' (' . $st['percent'] . '%) · ' . $tail;
        }

        $lines = [];

        if ($limits !== []) {
            $lines = ['💰 Limits', ...$limits];
        }

        if ($goals !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines = [...$lines, '🎯 Goals', ...$goals];
        }

        return $lines;
    }

    /**
     * @return array{count: int, income: float, expenses: float, top: ?array{tag: object, amount: float}}
     */
    private function summarize(User $user, Carbon $start, Carbon $end): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->onDays($start, $end)
            ->with('tags')
            ->get();

        $income = (float) $transactions->where('amount', '>', 0)->sum('amount');
        $expenses = (float) $transactions->where('amount', '<', 0)->sum('amount') * -1;

        $byTag = [];

        foreach ($transactions->where('amount', '<', 0) as $transaction) {
            foreach ($transaction->tags as $tag) {
                $byTag[$tag->id] ??= ['tag' => $tag, 'amount' => 0.0];
                $byTag[$tag->id]['amount'] += abs((float) $transaction->amount);
            }
        }

        return [
            'count' => $transactions->count(),
            'income' => $income,
            'expenses' => $expenses,
            'top' => collect($byTag)->sortByDesc('amount')->first(),
        ];
    }

    /**
     * @param array{count: int, income: float, expenses: float, top: ?array{tag: object, amount: float}} $current
     * @param array{count: int, income: float, expenses: float, top: ?array{tag: object, amount: float}} $previous
     */
    private function formatMessage(User $user, Carbon $start, Carbon $end, array $current, array $previous): string
    {
        $net = $current['income'] - $current['expenses'];
        $delta = $previous['count'] > 0
            ? $current['expenses'] - $previous['expenses']
            : null;

        $lines = [
            '📬 Weekly digest',
            UserFormatter::formatDate($user, $start) . ' – ' . UserFormatter::formatDate($user, $end),
            'Transactions: ' . $current['count'],
            'Income: +' . UserFormatter::formatMoney($user, $current['income']),
            'Expenses: ' . UserFormatter::formatMoney($user, $current['expenses']),
            'Net: ' . UserFormatter::formatMoney($user, $net),
        ];

        if ($current['top']) {
            $tag = $current['top']['tag'];
            $lines[] = 'Top: ' . trim($tag->emoji . ' ' . $tag->title)
                . ' — ' . UserFormatter::formatMoney($user, $current['top']['amount']);
        }

        if ($delta !== null) {
            $sign = $delta > 0 ? '+' : '';
            $lines[] = 'vs prior week: ' . $sign . UserFormatter::formatMoney($user, $delta) . ' expenses';
        }

        return implode("\n", $lines);
    }
}
