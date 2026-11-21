<?php

namespace App\Actions\Budgets;

use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Support\JsonPath;
use App\Support\LimitBuckets;
use App\Support\TagTree;
use Carbon\Carbon;

class CalculateBudgetPeriodSpentAction
{
    public function execute(BudgetPeriod $period, User $user, ?Carbon $asOf = null): float
    {
        $asOf ??= $user->nowInTimezone();
        $start = $this->currentBucketStart($period, $asOf);
        $scope = is_array($period->scope) ? $period->scope : null;
        $kind = $scope['kind'] ?? null;

        if ($kind === 'type' || $kind === 'field_sum') {
            $typeId = (int) ($scope['type_id'] ?? 0);

            if ($typeId <= 0) {
                return 0.0;
            }

            $query = Transaction::query()
                ->where('user_id', $user->id)
                ->onDays($start, $asOf)
                ->where('amount', '<', 0)
                ->where('transaction_type_id', $typeId);

            if ($kind === 'field_sum') {
                $field = (string) ($scope['field'] ?? '');

                if (!JsonPath::keyIsSafe($field)) {
                    return 0.0;
                }

                $expr = JsonPath::number('data', $field);
                $sum = $query->whereRaw("{$expr} IS NOT NULL")
                    ->selectRaw("COALESCE(SUM({$expr}), 0) as total")
                    ->value('total');

                return (float) $sum;
            }

            return (float) $query->sum('amount') * -1;
        }

        // A parent tag includes its child tags; a transaction counts once (whereHas).
        $tagIds = TagTree::for($user)->expand($period->tags->pluck('id'));

        if ($tagIds === []) {
            return 0.0;
        }

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
