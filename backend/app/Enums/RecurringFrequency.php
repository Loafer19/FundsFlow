<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum RecurringFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * The n-th occurrence (0-based), always counted from the anchor so
     * month-end rules don't drift: Jan 31 → Feb 28 → Mar 31, not Mar 28.
     */
    public function occurrence(CarbonInterface $anchor, int $n): CarbonImmutable
    {
        $anchor = CarbonImmutable::parse($anchor->toDateString());

        return match ($this) {
            self::Daily => $anchor->addDays($n),
            self::Weekly => $anchor->addWeeks($n),
            self::Monthly => $anchor->addMonthsNoOverflow($n),
            self::Yearly => $anchor->addYearsNoOverflow($n),
        };
    }

    /**
     * First occurrence on or after $date; the anchor itself when $date is earlier.
     */
    public function firstOnOrAfter(CarbonInterface $anchor, CarbonInterface $date): CarbonImmutable
    {
        $anchor = CarbonImmutable::parse($anchor->toDateString());
        $date = CarbonImmutable::parse($date->toDateString());

        if ($date->lte($anchor)) {
            return $anchor;
        }

        // Start one step below the target, then walk forward (at most two steps).
        $n = max(0, match ($this) {
            self::Daily => (int) $anchor->diffInDays($date),
            self::Weekly => intdiv((int) $anchor->diffInDays($date), 7),
            self::Monthly => ($date->year - $anchor->year) * 12 + ($date->month - $anchor->month) - 1,
            self::Yearly => $date->year - $anchor->year - 1,
        });

        while (($candidate = $this->occurrence($anchor, $n))->lt($date)) {
            $n++;
        }

        return $candidate;
    }
}
