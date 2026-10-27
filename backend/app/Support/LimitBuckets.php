<?php

namespace App\Support;

use App\Models\BudgetPeriod;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Bucket maths for spending limits shared by spent/alerts/cards/agents/API
 * (mirrored in frontend/src/services/limits.js).
 *
 * A limit's settings are versioned in budget_periods. Editing amount, length or
 * tags mid-bucket closes the open version and opens a new one the same day, but
 * the ACTIVE bucket still counts from its own first day: a version's effective
 * start is the start of the bucket containing its starts_at, never earlier than
 * the start of its run (the chain of back-to-back versions; a pause with a gap
 * breaks the chain). A closed version's history then ends the day before the
 * next back-to-back version's effective start, so no day is counted twice.
 */
final class LimitBuckets
{
    /**
     * @param iterable<BudgetPeriod> $periods all versions of the budget (any order)
     */
    public static function effectiveStart(BudgetPeriod $period, iterable $periods): string
    {
        $own = $period->starts_at->toDateString();
        [$bucketStart] = $period->length->bucketBounds(Carbon::parse($own), $period->budget?->anchor_date);
        $chainStart = self::chainStart($period, $periods);

        return max($bucketStart->toDateString(), $chainStart);
    }

    /**
     * First day of the bucket containing $asOf, clamped to the version's effective start.
     *
     * @param iterable<BudgetPeriod> $periods
     */
    public static function currentBucketStart(BudgetPeriod $period, iterable $periods, CarbonInterface $asOf): Carbon
    {
        [$boundary] = $period->bucketBounds($asOf);
        $start = max($boundary->toDateString(), self::effectiveStart($period, $periods));

        return Carbon::parse($start, $asOf->getTimezone())->startOfDay();
    }

    /**
     * Last day a closed version owns: its ends_at, or the day before the next
     * back-to-back version's effective start when that is earlier.
     *
     * @param iterable<BudgetPeriod> $periods
     */
    public static function closedEnd(BudgetPeriod $period, iterable $periods): ?string
    {
        if ($period->ends_at === null) {
            return null;
        }

        $end = $period->ends_at->toDateString();
        $next = self::sorted($periods)->first(
            fn (BudgetPeriod $p) => $p->id !== $period->id
                && $p->starts_at->toDateString() >= $period->starts_at->toDateString()
                && self::previous($p, $periods)?->id === $period->id,
        );

        if ($next) {
            $before = Carbon::parse(self::effectiveStart($next, $periods))->subDay()->toDateString();
            $end = min($end, $before);
        }

        return $end;
    }

    /**
     * Start of the run of back-to-back versions $period belongs to.
     *
     * @param iterable<BudgetPeriod> $periods
     */
    public static function chainStart(BudgetPeriod $period, iterable $periods): string
    {
        $current = $period;
        $guard = 0;

        while ($guard++ < 1000 && ($prev = self::previous($current, $periods))) {
            $current = $prev;
        }

        return $current->starts_at->toDateString();
    }

    /**
     * The closed version right before $period when it ran up to (or overlapped) $period's start.
     *
     * @param iterable<BudgetPeriod> $periods
     */
    public static function previous(BudgetPeriod $period, iterable $periods): ?BudgetPeriod
    {
        $start = $period->starts_at->toDateString();
        $dayBefore = Carbon::parse($start)->subDay()->toDateString();

        /** @var null|BudgetPeriod $prev */
        $prev = self::sorted($periods)
            ->filter(fn (BudgetPeriod $p) => $p->id !== $period->id
                && $p->ends_at !== null
                && ($p->starts_at->toDateString() < $start
                    || ($p->starts_at->toDateString() === $start && $p->id < $period->id)))
            ->last();

        if (!$prev || $prev->ends_at->toDateString() < $dayBefore) {
            return null;
        }

        return $prev;
    }

    /**
     * @param iterable<BudgetPeriod> $periods
     * @return Collection<int, BudgetPeriod>
     */
    private static function sorted(iterable $periods): Collection
    {
        return collect($periods)
            ->sortBy(fn (BudgetPeriod $p) => $p->starts_at->toDateString() . sprintf('|%012d', $p->id))
            ->values();
    }
}
