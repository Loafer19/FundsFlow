<?php

namespace App\Actions\Budgets;

use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class CalculateBudgetPeriodSpentAction
{
    public function execute(BudgetPeriod $period, User $user, ?Carbon $asOf = null): float
    {
        $tagIds = $period->tags->pluck('id');

        if ($tagIds->isEmpty()) {
            return 0.0;
        }

        $asOf ??= $user->nowInTimezone();
        $start = $this->currentBucketStart($period, $asOf);

        $sum = Transaction::query()
            ->where('user_id', $user->id)
            ->onDays($start, $asOf)
            ->where('amount', '<', 0)
            ->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds))
            ->sum('amount');

        return (float) $sum * -1;
    }

    /**
     * First day of the bucket containing $asOf, clamped to the period start
     * (a period that began mid-bucket only counts from its own start).
     */
    public function currentBucketStart(BudgetPeriod $period, Carbon $asOf): Carbon
    {
        [$boundary] = $period->bucketBounds($asOf);

        if ($boundary->toDateString() > $period->starts_at->toDateString()) {
            return $boundary;
        }

        return $period->starts_at->copy()->startOfDay();
    }

    /**
     * Last day of the bucket containing $asOf.
     */
    public function currentBucketEnd(BudgetPeriod $period, Carbon $asOf): Carbon
    {
        [, $next] = $period->bucketBounds($asOf);

        return $next->copy()->subDay();
    }
}

