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
            ->whereBetween('at', [$start->toDateString(), $asOf->toDateString()])
            ->where('amount', '<', 0)
            ->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds))
            ->sum('amount');

        return (float) $sum * -1;
    }

    public function currentBucketStart(BudgetPeriod $period, Carbon $asOf): Carbon
    {
        $boundary = $period->length->calendarStart($asOf);

        if ($boundary->toDateString() > $period->starts_at->toDateString()) {
            return $boundary;
        }

        return $period->starts_at->copy()->startOfDay();
    }
}

