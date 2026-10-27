<?php

namespace App\Actions\Budgets;

use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Support\LimitBuckets;
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
     * First day of the active bucket containing $asOf. A version opened mid-bucket by an
     * edit (amount/length/tags) still counts from the bucket's first day, but never from
     * before its run started (see LimitBuckets).
     */
    public function currentBucketStart(BudgetPeriod $period, Carbon $asOf): Carbon
    {
        return LimitBuckets::currentBucketStart($period, $period->siblings(), $asOf);
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

