<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A named (preset) or explicit date range in the user's timezone, plus the
 * matching previous range (previous calendar period for presets, the same
 * number of days right before for explicit ranges). Dates are whole days.
 */
final class PeriodRange
{
    public const PRESETS = [
        'this_week',
        'last_week',
        'this_month',
        'last_month',
        'last_7_days',
        'last_30_days',
        'this_quarter',
        'last_quarter',
        'this_year',
        'last_year',
    ];

    /** Explicit ranges are clamped to this many years. */
    public const MAX_YEARS = 3;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly CarbonImmutable $previousFrom,
        public readonly CarbonImmutable $previousTo,
        public readonly string $previousLabel,
        /** Last day of the whole period (after `to` when the period is still running). */
        public readonly CarbonImmutable $fullTo,
        public readonly CarbonImmutable $today,
        public readonly ?string $preset,
    ) {}

    public static function today(User $user): CarbonImmutable
    {
        return CarbonImmutable::parse($user->todayDateString(), $user->timezone())->startOfDay();
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    /**
     * @param array<string, mixed> $args period (preset) or from/to (YYYY-MM-DD)
     */
    public static function resolve(User $user, array $args, string $default = 'this_month'): self
    {
        $tz = $user->timezone();
        $today = self::today($user);

        if (self::isDate($args['from'] ?? null) || self::isDate($args['to'] ?? null)) {
            $from = self::isDate($args['from'] ?? null) ? CarbonImmutable::parse($args['from'], $tz)->startOfDay() : $today->startOfMonth();
            $to = self::isDate($args['to'] ?? null) ? CarbonImmutable::parse($args['to'], $tz)->startOfDay() : $today;

            return self::explicit($from, $to, $today);
        }

        $preset = in_array($args['period'] ?? null, self::PRESETS, true) ? (string) $args['period'] : $default;

        return self::preset($preset, $today);
    }

    public static function explicit(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, string $label = 'Custom period'): self
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->lt($to->subYears(self::MAX_YEARS))) {
            $from = $to->subYears(self::MAX_YEARS);
        }

        if ($label === 'Custom period' && ($calendar = self::calendarRange($from, $to, $today)) !== null) {
            return $calendar;
        }

        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        return new self($from, $to, $label, $previousFrom, $previousTo, "Previous {$days} days", $to, $today, null);
    }

    /**
     * A from/to range that is exactly one calendar month, quarter or year (e.g. the web date picker)
     * compares with the previous calendar month/quarter/year and gets a readable label.
     * A range that is still running is cut at today, like the this_* presets.
     */
    private static function calendarRange(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): ?self
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        if ($from->day !== 1 || $from->gt($today)) {
            return null;
        }

        $kind = match (true) {
            $to->equalTo($from->endOfMonth()->startOfDay()) => 'month',
            $from->month % 3 === 1 && $to->equalTo($from->endOfQuarter()->startOfDay()) => 'quarter',
            $from->month === 1 && $to->equalTo($from->endOfYear()->startOfDay()) => 'year',
            default => null,
        };
        if ($kind === null) {
            return null;
        }

        [$prevFrom, $label, $prevLabel] = match ($kind) {
            'month' => [$from->subMonthNoOverflow(), $from->format('F Y'), $from->subMonthNoOverflow()->format('F Y')],
            'quarter' => [$from->subMonthsNoOverflow(3), 'Q' . $from->quarter . ' ' . $from->year, 'Q' . $from->subMonthsNoOverflow(3)->quarter . ' ' . $from->subMonthsNoOverflow(3)->year],
            'year' => [$from->subYear(), (string) $from->year, (string) ($from->year - 1)],
        };
        $prevTo = $from->subDay();
        $cutTo = $to->gt($today) ? $today : $to;

        return new self($from, $cutTo, $label, $prevFrom, $prevTo, $prevLabel, $to, $today, null);
    }

    public static function preset(string $preset, CarbonImmutable $today): self
    {
        $quarter = fn (CarbonImmutable $d): string => 'Q' . $d->quarter . ' ' . $d->year;
        $month = fn (CarbonImmutable $d): string => $d->format('F Y');

        // [from, to, label, prevFrom, prevTo, prevLabel, fullTo]
        $r = match ($preset) {
            'this_week' => (function () use ($today) {
                $start = $today->startOfWeek();
                $prev = $start->subWeek();

                return [$start, $today, 'This week', $prev, $prev->addDays(6), 'Last week', $start->addDays(6)];
            })(),
            'last_week' => (function () use ($today) {
                $start = $today->startOfWeek()->subWeek();
                $prev = $start->subWeek();

                return [$start, $start->addDays(6), 'Last week', $prev, $prev->addDays(6), 'Week before', $start->addDays(6)];
            })(),
            'last_month' => (function () use ($today, $month) {
                $start = $today->startOfMonth()->subMonthNoOverflow();
                $prev = $start->subMonthNoOverflow();

                return [$start, $start->endOfMonth()->startOfDay(), 'Last month (' . $month($start) . ')', $prev, $prev->endOfMonth()->startOfDay(), $month($prev), $start->endOfMonth()->startOfDay()];
            })(),
            'last_7_days' => [$today->subDays(6), $today, 'Last 7 days', $today->subDays(13), $today->subDays(7), 'Previous 7 days', $today],
            'last_30_days' => [$today->subDays(29), $today, 'Last 30 days', $today->subDays(59), $today->subDays(30), 'Previous 30 days', $today],
            'this_quarter' => (function () use ($today, $quarter) {
                $start = $today->startOfQuarter();
                $prev = $start->subMonthsNoOverflow(3)->startOfQuarter();

                return [$start, $today, 'This quarter (' . $quarter($start) . ')', $prev, $prev->endOfQuarter()->startOfDay(), $quarter($prev), $start->endOfQuarter()->startOfDay()];
            })(),
            'last_quarter' => (function () use ($today, $quarter) {
                $start = $today->startOfQuarter()->subMonthsNoOverflow(3)->startOfQuarter();
                $prev = $start->subMonthsNoOverflow(3)->startOfQuarter();

                return [$start, $start->endOfQuarter()->startOfDay(), 'Last quarter (' . $quarter($start) . ')', $prev, $prev->endOfQuarter()->startOfDay(), $quarter($prev), $start->endOfQuarter()->startOfDay()];
            })(),
            'this_year' => (function () use ($today) {
                $start = $today->startOfYear();
                $prev = $start->subYear();

                return [$start, $today, 'This year (' . $start->year . ')', $prev, $prev->endOfYear()->startOfDay(), (string) $prev->year, $start->endOfYear()->startOfDay()];
            })(),
            'last_year' => (function () use ($today) {
                $start = $today->startOfYear()->subYear();
                $prev = $start->subYear();

                return [$start, $start->endOfYear()->startOfDay(), 'Last year (' . $start->year . ')', $prev, $prev->endOfYear()->startOfDay(), (string) $prev->year, $start->endOfYear()->startOfDay()];
            })(),
            default => (function () use ($today, $month) { // this_month
                $start = $today->startOfMonth();
                $prev = $start->subMonthNoOverflow();

                return [$start, $today, 'This month (' . $month($start) . ')', $prev, $prev->endOfMonth()->startOfDay(), $month($prev), $start->endOfMonth()->startOfDay()];
            })(),
        };

        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'this_month';

        return new self($r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $today, $preset);
    }

    /** Days covered so far (from..to). */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** True while the period is still running (to-date preset such as this_month). */
    public function isPartial(): bool
    {
        return $this->fullTo->gt($this->to);
    }

    /** Previous period cut at the same elapsed day count (fair "to date" comparison). */
    public function previousToDate(): CarbonImmutable
    {
        $cut = $this->previousFrom->addDays($this->days() - 1);

        return $cut->gt($this->previousTo) ? $this->previousTo : $cut;
    }

    public function previous(): self
    {
        return self::explicit($this->previousFrom, $this->previousTo, $this->today, $this->previousLabel);
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'label' => $this->label];
    }
}
