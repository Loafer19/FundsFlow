<?php

namespace App\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;

enum BudgetLength: string
{
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * Grid of a period without its own anchor (rows created before anchors existed):
     * 2001-01-01 is a Monday and the 1st of January, so anchoring to it gives plain
     * calendar weeks (Mon–Sun), months and years.
     */
    public const CALENDAR_ANCHOR = '2001-01-01';

    public function calendarStart(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy();

        return match ($this) {
            self::Week => $now->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $now->startOfMonth(),
            self::Year => $now->startOfYear(),
        };
    }

    /**
     * True when $date starts a calendar week (Monday), month or year: anchoring a limit
     * there is the same grid as no anchor, so such start dates are stored as null.
     */
    public function isCalendarStart(CarbonInterface $date): bool
    {
        [$start] = $this->bucketBounds($date);

        return $start->toDateString() === $date->toDateString();
    }

    /**
     * budgets.anchor_date for a limit starting on $startDate: null on calendar boundaries.
     */
    public function anchorFor(?string $startDate): ?string
    {
        if ($startDate === null || $this->isCalendarStart(Carbon::parse($startDate))) {
            return null;
        }

        return $startDate;
    }

    /**
     * The bucket (one week/month/year of a limit) containing $date, on a grid that
     * repeats from $anchor: weekly on the anchor's weekday, monthly on its day of
     * the month, yearly on its date. Steps are always counted from the anchor (no
     * overflow), so an anchor on the 31st gives 28/29 Feb and then 31 Mar again.
     * Mirrors bucketBounds() in frontend/src/services/limits.js.
     *
     * @return array{0: Carbon, 1: Carbon} [first day, first day of the next bucket], in $date's timezone
     */
    public function bucketBounds(CarbonInterface $date, ?CarbonInterface $anchor = null): array
    {
        $tz = $date->getTimezone();
        $day = Carbon::parse($date->toDateString(), $tz);
        $base = Carbon::parse(($anchor ?? Carbon::parse(self::CALENDAR_ANCHOR))->toDateString(), $tz);

        $n = match ($this) {
            self::Week => intdiv($this->daysBetween($base, $day), 7),
            self::Month => ($day->year - $base->year) * 12 + ($day->month - $base->month),
            self::Year => $day->year - $base->year,
        };

        while ($n > -100_000 && $this->shift($base, $n)->greaterThan($day)) {
            $n--;
        }

        while ($n < 100_000 && $this->shift($base, $n + 1)->lessThanOrEqualTo($day)) {
            $n++;
        }

        return [$this->shift($base, $n), $this->shift($base, $n + 1)];
    }

    private function shift(Carbon $base, int $steps): Carbon
    {
        return match ($this) {
            self::Week => $base->copy()->addDays(7 * $steps),
            self::Month => $base->copy()->addMonthsNoOverflow($steps),
            self::Year => $base->copy()->addYearsNoOverflow($steps),
        };
    }

    private function daysBetween(Carbon $from, Carbon $to): int
    {
        $utcDay = fn (Carbon $d): int => intdiv((int) strtotime($d->toDateString() . ' 00:00:00 UTC'), 86400);

        return $utcDay($to) - $utcDay($from);
    }
}
