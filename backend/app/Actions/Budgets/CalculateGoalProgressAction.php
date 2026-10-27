<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Progress of a savings goal (budget type "goal").
 *
 * Contribution rule (mirrored in frontend/src/services/goals.js):
 * - Contributions are the user's transactions carrying ANY of the goal's tags
 *   (exact tags, no child tags — same matching as spending limits; a
 *   transaction with several matching tags counts once), dated from the goal
 *   start (its first period's starts_at) through today.
 * - Money moved into savings is usually logged as an expense (negative amount),
 *   so saved = -(net of those transactions): a -500 transfer adds 500, a +200
 *   withdrawal back to the wallet takes 200 off.
 * - Progress = start_amount + saved, never below zero. Pausing a goal does not
 *   reset it: progress always counts from the original start.
 * - Pace = contributions over the last ~3 months (or since start when newer),
 *   per day over at least 30 days, so one early transfer does not project an
 *   unrealistic rate.
 */
class CalculateGoalProgressAction
{
    public const PACE_WINDOW_DAYS = 90;

    public const MIN_PACE_DAYS = 30;

    public const DAYS_PER_MONTH = 30.4375;

    /** Below this many days to the deadline the required pace is given per week. */
    public const WEEKLY_BELOW_DAYS = 61;

    /**
     * @return array{
     *     target: float, start_amount: float, contributed: float, saved: float, remaining: float,
     *     percent: int, completed: bool, status: string, started_at: string, target_date: ?string,
     *     days_left: ?int, overdue: bool, required_amount: ?float, required_per: ?string,
     *     monthly_pace: float, projected_date: ?string, tag_ids: list<int>
     * }
     */
    public function execute(Budget $budget, User $user, ?Carbon $asOf = null): array
    {
        $budget->loadMissing('periods.tags');
        $periods = $budget->periods;

        /** @var null|BudgetPeriod $current */
        $current = $periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);
        $latest = $current ?? $periods->sortByDesc(fn (BudgetPeriod $p) => $p->starts_at?->toDateString())->first();
        $first = $periods->sortBy(fn (BudgetPeriod $p) => $p->starts_at?->toDateString())->first();

        $today = CarbonImmutable::parse(($asOf ?? $user->nowInTimezone())->toDateString());
        $startedAt = $first?->starts_at ? CarbonImmutable::parse($first->starts_at->toDateString()) : $today;
        $tagIds = $latest ? $latest->tags->pluck('id')->map(fn ($id) => (int) $id)->values()->all() : [];
        $target = $latest ? (float) $latest->amount : 0.0;
        $startAmount = (float) ($budget->start_amount ?? 0);

        $windowStart = $today->subDays(self::PACE_WINDOW_DAYS - 1);
        $windowStart = $windowStart->lessThan($startedAt) ? $startedAt : $windowStart;

        $contributed = $this->contributed($user, $tagIds, $startedAt, $today);
        $windowContributed = $windowStart->equalTo($startedAt)
            ? $contributed
            : $this->contributed($user, $tagIds, $windowStart, $today);

        $saved = max(0.0, round($startAmount + $contributed, 2));
        $remaining = max(0.0, round($target - $saved, 2));
        $completed = $target > 0 && $saved >= $target;

        $windowDays = max(self::MIN_PACE_DAYS, (int) $windowStart->diffInDays($today) + 1);
        $perDay = $windowContributed > 0 ? $windowContributed / $windowDays : 0.0;
        $projected = !$completed && $perDay > 0 ? $today->addDays((int) ceil($remaining / $perDay))->toDateString() : null;

        $deadline = $budget->target_date ? CarbonImmutable::parse($budget->target_date->toDateString()) : null;
        $daysLeft = $deadline ? (int) $today->diffInDays($deadline, false) : null;
        $overdue = $daysLeft !== null && $daysLeft < 0 && !$completed;

        $requiredAmount = null;
        $requiredPer = null;

        // No pace to ask for once the deadline has passed (it would read "need it all this week").
        if ($deadline && !$completed && !$overdue && $remaining > 0) {
            $left = max(1, $daysLeft);
            $requiredPer = $left < self::WEEKLY_BELOW_DAYS ? 'week' : 'month';
            $units = $requiredPer === 'week' ? max(1.0, $left / 7) : $left / self::DAYS_PER_MONTH;
            $requiredAmount = round($remaining / $units, 2);
        }

        $status = match (true) {
            $completed => 'completed',
            $current === null => 'paused',
            $deadline === null => 'in_progress',
            $overdue => 'behind',
            $projected !== null && $projected <= $deadline->toDateString() => 'on_track',
            default => 'behind',
        };

        return [
            'target' => $target,
            'start_amount' => $startAmount,
            'contributed' => round($contributed, 2) + 0.0,
            'saved' => $saved,
            'remaining' => $remaining,
            'percent' => $target > 0 ? (int) floor(min($saved / $target, 9.99) * 100) : 0,
            'completed' => $completed,
            'status' => $status,
            'started_at' => $startedAt->toDateString(),
            'target_date' => $deadline?->toDateString(),
            'days_left' => $daysLeft === null ? null : max(0, $daysLeft),
            'overdue' => $overdue,
            'required_amount' => $requiredAmount,
            'required_per' => $requiredPer,
            'monthly_pace' => round($perDay * self::DAYS_PER_MONTH, 2),
            'projected_date' => $projected,
            'tag_ids' => $tagIds,
        ];
    }

    /**
     * Net saved by the tagged transactions in [from, to]: minus their signed sum.
     *
     * @param list<int> $tagIds
     */
    public function contributed(User $user, array $tagIds, CarbonImmutable $from, CarbonImmutable $to): float
    {
        if ($tagIds === [] || $from->greaterThan($to)) {
            return 0.0;
        }

        $sum = Transaction::query()
            ->where('user_id', $user->id)
            ->onDays($from, $to)
            ->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds))
            ->sum('amount');

        return round(-1 * (float) $sum, 2) + 0.0;
    }
}
