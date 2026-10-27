<?php

namespace App\Actions\Budgets;

use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\Carbon;

/**
 * Snapshot of a spending limit's active bucket (Telegram card, digest, agents,
 * API, PDF report). Mirrors limitProgress() in frontend/src/services/limits.js.
 */
class CalculateLimitStatusAction
{
    public function __construct(private readonly CalculateBudgetPeriodSpentAction $calculateSpent) {}

    /**
     * @return array{
     *     start: string, end: string, days_total: int, days_elapsed: int, days_left: int,
     *     limit: float, spent: float, left: float, percent: int, status: string,
     *     per_day_allowance: float, avg_daily_spend: float, projected_spend: float
     * }
     */
    public function execute(BudgetPeriod $period, User $user, ?Carbon $now = null): array
    {
        $now ??= $user->nowInTimezone();
        $start = $this->calculateSpent->currentBucketStart($period, $now);
        $end = $this->calculateSpent->currentBucketEnd($period, $now);

        // Whole calendar days from date strings: starts_at and "today" can carry different timezones.
        $day = fn (Carbon $d): int => intdiv((int) strtotime($d->toDateString() . ' 00:00:00 UTC'), 86400);
        $totalDays = max(1, $day($end) - $day($start) + 1);
        $elapsed = max(1, min($totalDays, $day($now) - $day($start) + 1));
        $daysLeft = max(1, $totalDays - $elapsed + 1); // today counts as a day left to spend

        $limit = (float) $period->amount;
        $spent = round($this->calculateSpent->execute($period, $user, $now), 2) + 0.0; // + 0.0 turns -0.0 into 0.0
        $left = round($limit - $spent, 2);
        $projected = round($spent / $elapsed * $totalDays, 2);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days_total' => $totalDays,
            'days_elapsed' => $elapsed,
            'days_left' => $daysLeft,
            'limit' => $limit,
            'spent' => $spent,
            'left' => $left,
            'percent' => self::percent($spent, $limit),
            'status' => $spent >= $limit && $limit > 0 ? 'over' : ($projected > $limit ? 'at_risk' : 'on_track'),
            'per_day_allowance' => $left > 0 ? round($left / $daysLeft, 2) : 0.0,
            'avg_daily_spend' => round($spent / $elapsed, 2),
            'projected_spend' => $projected,
        ];
    }

    /**
     * Whole percent, rounded down (99.6% is not yet 100%), like savings goals.
     */
    public static function percent(float $spent, float $limit): int
    {
        return $limit > 0 ? (int) floor(round($spent / $limit * 100, 6)) : 0;
    }
}
