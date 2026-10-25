<?php

namespace App\Actions\Charts;

use App\Actions\Transactions\ListTransactionsAction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PeriodRange;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Builds chart data from the user's own transactions and renders a
 * self-contained HTML page (inline SVG, no external assets/JS) suitable
 * for a Gotenberg Chromium screenshot.
 */
class BuildUserChartAction
{
    public const TYPES = ['expenses_by_tag', 'income_vs_expenses', 'balance_trend'];

    public const PERIODS = PeriodRange::PRESETS;

    /** Chart types that support `compare` (current vs previous period). */
    public const COMPARABLE_TYPES = ['expenses_by_tag', 'income_vs_expenses'];

    /** Short labels kept for the original presets (Telegram captions unchanged). */
    private const LEGACY_LABELS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_year' => 'This year',
    ];

    public const WIDTH = 1000;

    public const HEIGHT = 620;

    private const PALETTE = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#06b6d4', '#8b5cf6', '#ec4899', '#84cc16', '#94a3b8'];

    public function __construct(
        private readonly ListTransactionsAction $listTransactions,
    ) {}

    /**
     * @param array<string, mixed> $args
     * `data` is the same chart as structured series (for clients that draw charts themselves, e.g. the web agent).
     *
     * @return array{html: string, caption: string, summary: array<string, mixed>, data: array<string, mixed>}
     */
    public function execute(User $user, array $args): array
    {
        $type = (string) ($args['type'] ?? 'expenses_by_tag');
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown chart type: ' . $type);
        }

        $period = PeriodRange::resolve($user, $args);
        [$from, $to] = [$period->from, $period->to];
        $periodLabel = $period->preset !== null ? (self::LEGACY_LABELS[$period->preset] ?? $period->label) : $period->label;
        $fromKey = $from->toDateString();
        $toKey = $to->toDateString();

        // ListTransactionsAction is scoped by the user (authorized + $user->transactions()).
        $all = $this->listTransactions->execute($user);
        $inRange = $this->between($all, $fromKey, $toKey);

        $income = (float) $inRange->filter(fn (Transaction $tx) => $tx->amount > 0)->sum('amount');
        $expenses = (float) $inRange->filter(fn (Transaction $tx) => $tx->amount < 0)->sum('amount');
        $net = $income + $expenses;
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);
        $range = UserFormatter::formatDate($user, $from) . ' – ' . UserFormatter::formatDate($user, $to);

        $data = [];
        $previous = null;
        $compare = filter_var($args['compare'] ?? false, FILTER_VALIDATE_BOOL) && in_array($type, self::COMPARABLE_TYPES, true);

        if ($compare) {
            $prevIn = $this->between($all, $period->previousFrom->toDateString(), $period->previousTo->toDateString());
            $prevIncome = (float) $prevIn->filter(fn (Transaction $tx) => $tx->amount > 0)->sum('amount');
            $prevExpenses = (float) $prevIn->filter(fn (Transaction $tx) => $tx->amount < 0)->sum('amount');
            $previous = [
                'from' => $period->previousFrom->toDateString(),
                'to' => $period->previousTo->toDateString(),
                'period_label' => $period->previousLabel,
                'income' => $prevIncome,
                'expenses' => $prevExpenses,
                'net' => $prevIncome + $prevExpenses,
                'partial_current' => $period->isPartial(),
            ];
        }

        switch ($compare ? $type . '_compare' : $type) {
            case 'expenses_by_tag_compare':
                $rows = $this->compareByTag($inRange, $prevIn);
                $title = 'Expenses by tag: ' . $periodLabel . ' vs ' . $period->previousLabel;
                $svg = $this->svgCompareBars($rows, $money, $periodLabel, $period->previousLabel);
                $caption = "Expenses by tag · {$periodLabel} vs {$period->previousLabel}\n"
                    . 'Now: ' . $money($expenses) . ' · Before: ' . $money($previous['expenses'])
                    . $this->deltaText($expenses, $previous['expenses']);
                if ($period->isPartial()) {
                    $caption .= "\nCurrent period is still in progress.";
                }
                $summary = ['rows' => count($rows), 'compare' => true];
                $data = ['style' => 'compare', 'rows' => $rows];
                break;

            case 'income_vs_expenses_compare':
                $buckets = [
                    ['label' => mb_strimwidth($period->previousLabel, 0, 24, '…'), 'date' => $previous['from'], 'income' => $previous['income'], 'expenses' => abs($previous['expenses'])],
                    ['label' => mb_strimwidth($periodLabel, 0, 24, '…'), 'date' => $fromKey, 'income' => $income, 'expenses' => abs($expenses)],
                ];
                $title = 'Income vs expenses: ' . $periodLabel . ' vs ' . $period->previousLabel;
                $svg = $this->svgGroupedBars($buckets, $money);
                $caption = "Income vs expenses · {$periodLabel} vs {$period->previousLabel}\n"
                    . 'Income: ' . $money($income) . ' (before ' . $money($previous['income']) . ')'
                    . "\nExpenses: " . $money($expenses) . ' (before ' . $money($previous['expenses']) . ')'
                    . "\nNet: " . $money($net) . ' (before ' . $money($previous['net']) . ')';
                if ($period->isPartial()) {
                    $caption .= "\nCurrent period is still in progress.";
                }
                $summary = ['buckets' => 2, 'group_by' => 'period', 'compare' => true];
                $data = ['group_by' => 'period', 'buckets' => $buckets];
                break;

            case 'expenses_by_tag':
                $style = ($args['style'] ?? 'donut') === 'bar' ? 'bar' : 'donut';
                $rows = $this->expensesByTag($inRange);
                $title = 'Expenses by tag';
                $svg = $style === 'bar' ? $this->svgHorizontalBars($rows, $money) : $this->svgDonut($rows, $money, $expenses);
                $caption = "Expenses by tag · {$periodLabel}\nTotal expenses: " . $money($expenses);
                if ($rows !== []) {
                    $caption .= "\nTop: " . $rows[0]['label'] . ' ' . $money(-$rows[0]['value']);
                }
                $summary = ['rows' => count($rows)];
                $data = ['style' => $style, 'rows' => $rows];
                break;

            case 'income_vs_expenses':
                $group = in_array($args['group_by'] ?? null, ['day', 'week', 'month'], true)
                    ? (string) $args['group_by']
                    : $this->defaultGrouping($from, $to);
                $buckets = $this->incomeExpenseBuckets($inRange, $from, $to, $group, $user);
                $title = 'Income vs expenses by ' . $group;
                $svg = $this->svgGroupedBars($buckets, $money);
                $caption = "Income vs expenses · {$periodLabel}\nIncome: " . $money($income)
                    . "\nExpenses: " . $money($expenses) . "\nNet: " . $money($net);
                $summary = ['buckets' => count($buckets), 'group_by' => $group];
                $data = ['group_by' => $group, 'buckets' => $buckets];
                break;

            default: // balance_trend
                $points = $this->balancePoints($all, $from, $to);
                $title = 'Balance trend';
                $svg = $this->svgLine($points, $money);
                $start = $points[0]['value'] ?? 0.0;
                $end = $points[count($points) - 1]['value'] ?? 0.0;
                $caption = "Balance trend · {$periodLabel}\nStart: " . $money($start)
                    . "\nEnd: " . $money($end) . "\nChange: " . $money($end - $start);
                $summary = ['start' => $start, 'end' => $end];
                $data = ['points' => $points];
                break;
        }

        $html = $this->page($title, $periodLabel . ' · ' . $range, $svg);

        return [
            'html' => $html,
            'caption' => mb_substr($caption, 0, 1000),
            'data' => array_merge($data, [
                'type' => $type,
                'title' => $title,
                'subtitle' => $periodLabel . ' · ' . $range,
                'period_label' => $periodLabel,
                'from' => $fromKey,
                'to' => $toKey,
                'income' => $income,
                'expenses' => $expenses,
                'net' => $net,
            ], $previous !== null ? ['previous' => $previous] : []),
            'summary' => array_merge($summary, $previous !== null ? ['previous' => $previous] : [], [
                'type' => $type,
                'from' => $fromKey,
                'to' => $toKey,
                'income' => $income,
                'expenses' => $expenses,
                'net' => $net,
                'transaction_count' => $inRange->count(),
            ]),
        ];
    }

    /**
     * @param iterable<Transaction> $all
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function between(iterable $all, string $fromKey, string $toKey): \Illuminate\Support\Collection
    {
        return collect($all)->filter(function (Transaction $tx) use ($fromKey, $toKey): bool {
            if (!$tx->at) {
                return false;
            }
            $d = $tx->at->format('Y-m-d');

            return $d >= $fromKey && $d <= $toKey;
        })->values();
    }

    /**
     * Expense totals per tag for both periods (top 7 by the larger side + Other).
     *
     * @param iterable<Transaction> $current
     * @param iterable<Transaction> $previous
     * @return list<array{label: string, value: float, previous: float, id: int|string, emoji: string, title: string, count: int}>
     */
    private function compareByTag(iterable $current, iterable $previous): array
    {
        $rows = [];
        foreach (['value' => $current, 'previous' => $previous] as $side => $transactions) {
            foreach ($this->expensesByTagAll($transactions) as $key => $row) {
                $rows[$key] ??= ['label' => $row['label'], 'value' => 0.0, 'previous' => 0.0, 'id' => $row['id'], 'emoji' => $row['emoji'], 'title' => $row['title'], 'count' => 0];
                $rows[$key][$side] += $row['value'];
                if ($side === 'value') {
                    $rows[$key]['count'] += $row['count'];
                }
            }
        }

        $rows = collect($rows)->sortByDesc(fn (array $r) => max($r['value'], $r['previous']))->values();
        if ($rows->count() > 8) {
            $rest = $rows->slice(7);
            $rows = $rows->take(7)->push([
                'label' => 'Other', 'value' => (float) $rest->sum('value'), 'previous' => (float) $rest->sum('previous'),
                'id' => 'other', 'emoji' => '', 'title' => 'Other', 'count' => (int) $rest->sum('count'),
            ]);
        }

        return $rows->all();
    }

    /**
     * @param iterable<Transaction> $transactions
     * @return array<int|string, array{label: string, value: float, id: int|string, emoji: string, title: string, count: int}>
     */
    private function expensesByTagAll(iterable $transactions): array
    {
        $byTag = [];
        foreach ($transactions as $tx) {
            if ($tx->amount >= 0) {
                continue;
            }
            $amount = abs((float) $tx->amount);
            if ($tx->tags->isEmpty()) {
                $byTag['untagged'] ??= ['label' => 'Untagged', 'value' => 0.0, 'id' => 'untagged', 'emoji' => '🏷️', 'title' => 'Untagged', 'count' => 0];
                $byTag['untagged']['value'] += $amount;
                $byTag['untagged']['count']++;
                continue;
            }
            foreach ($tx->tags as $tag) {
                $byTag[$tag->id] ??= ['label' => trim(((string) $tag->emoji) . ' ' . $tag->title), 'value' => 0.0, 'id' => (int) $tag->id, 'emoji' => (string) $tag->emoji, 'title' => (string) $tag->title, 'count' => 0];
                $byTag[$tag->id]['value'] += $amount;
                $byTag[$tag->id]['count']++;
            }
        }

        return $byTag;
    }

    /** " (+12% vs before)" for expense totals (negative numbers). */
    private function deltaText(float $now, float $before): string
    {
        if (abs($before) < 0.005) {
            return '';
        }
        $pct = (int) round((abs($now) - abs($before)) / abs($before) * 100);

        return ' (' . ($pct >= 0 ? '+' : '') . $pct . '%)';
    }

    private function defaultGrouping(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = $from->diffInDays($to) + 1;

        return $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
    }

    /**
     * @param iterable<Transaction> $transactions
     * @return list<array{label: string, value: float, id: int|string, emoji: string, title: string, count: int}>
     */
    private function expensesByTag(iterable $transactions): array
    {
        $byTag = [];
        foreach ($transactions as $tx) {
            if ($tx->amount >= 0) {
                continue;
            }
            $amount = abs((float) $tx->amount);
            if ($tx->tags->isEmpty()) {
                $byTag['untagged'] ??= ['label' => 'Untagged', 'value' => 0.0, 'id' => 'untagged', 'emoji' => '🏷️', 'title' => 'Untagged', 'count' => 0];
                $byTag['untagged']['value'] += $amount;
                $byTag['untagged']['count']++;
                continue;
            }
            foreach ($tx->tags as $tag) {
                $byTag[$tag->id] ??= [
                    'label' => trim(((string) $tag->emoji) . ' ' . $tag->title),
                    'value' => 0.0,
                    'id' => (int) $tag->id,
                    'emoji' => (string) $tag->emoji,
                    'title' => (string) $tag->title,
                    'count' => 0,
                ];
                $byTag[$tag->id]['value'] += $amount;
                $byTag[$tag->id]['count']++;
            }
        }

        $rows = collect($byTag)->sortByDesc('value')->values();
        if ($rows->count() > 8) {
            $other = (float) $rows->slice(7)->sum('value');
            $otherCount = (int) $rows->slice(7)->sum('count');
            $rows = $rows->take(7)->push(['label' => 'Other', 'value' => $other, 'id' => 'other', 'emoji' => '', 'title' => 'Other', 'count' => $otherCount]);
        }

        return $rows->all();
    }

    /**
     * @param iterable<Transaction> $transactions
     * @return list<array{label: string, date: string, income: float, expenses: float}>
     */
    private function incomeExpenseBuckets(iterable $transactions, CarbonImmutable $from, CarbonImmutable $to, string $group, User $user): array
    {
        $keyOf = fn (Carbon|CarbonImmutable $d): string => match ($group) {
            'month' => $d->format('Y-m'),
            'week' => $d->copy()->startOfWeek()->format('Y-m-d'),
            default => $d->format('Y-m-d'),
        };

        $buckets = [];
        $cursor = $from;
        while ($cursor->lte($to) && count($buckets) < 400) {
            $key = $keyOf($cursor);
            if (!isset($buckets[$key])) {
                $label = match ($group) {
                    'month' => $cursor->format('M y'),
                    'week' => $cursor->startOfWeek()->format('d M'),
                    default => $cursor->format('d'),
                };
                $date = match ($group) {
                    'month' => $cursor->startOfMonth()->toDateString(),
                    'week' => $cursor->startOfWeek()->toDateString(),
                    default => $cursor->toDateString(),
                };
                $buckets[$key] = ['label' => $label, 'date' => $date, 'income' => 0.0, 'expenses' => 0.0];
            }
            $cursor = $cursor->addDay();
        }

        foreach ($transactions as $tx) {
            $key = $keyOf($tx->at);
            if (!isset($buckets[$key])) {
                continue;
            }
            if ($tx->amount > 0) {
                $buckets[$key]['income'] += (float) $tx->amount;
            } else {
                $buckets[$key]['expenses'] += abs((float) $tx->amount);
            }
        }

        return array_values($buckets);
    }

    /**
     * @param iterable<Transaction> $all
     * @return list<array{label: string, date: string, value: float}>
     */
    private function balancePoints(iterable $all, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromKey = $from->toDateString();
        $opening = 0.0;
        $daily = [];
        foreach ($all as $tx) {
            if (!$tx->at) {
                continue;
            }
            $d = $tx->at->format('Y-m-d');
            if ($d < $fromKey) {
                $opening += (float) $tx->amount;
            } else {
                $daily[$d] = ($daily[$d] ?? 0.0) + (float) $tx->amount;
            }
        }

        $points = [];
        $balance = $opening;
        $cursor = $from;
        while ($cursor->lte($to) && count($points) < 1200) {
            $key = $cursor->toDateString();
            $balance += $daily[$key] ?? 0.0;
            $points[] = ['label' => $cursor->format('d M'), 'date' => $key, 'value' => round($balance, 2)];
            $cursor = $cursor->addDay();
        }

        return $points;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function emptySvg(): string
    {
        return '<text x="500" y="260" text-anchor="middle" font-size="26" fill="#64748b">No transactions in this period</text>';
    }

    /**
     * @param list<array{label: string, value: float}> $rows
     */
    private function svgDonut(array $rows, callable $money, float $expenses): string
    {
        $total = array_sum(array_column($rows, 'value'));
        if ($total <= 0) {
            return $this->emptySvg();
        }
        $cx = 260; $cy = 260; $r = 200; $ri = 120;
        $out = '';
        $angle = -M_PI / 2;
        foreach ($rows as $i => $row) {
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $sweep = $row['value'] / $total * 2 * M_PI;
            if ($sweep >= 2 * M_PI - 1e-6) {
                $out .= sprintf('<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="%d"/>', $cx, $cy, ($r + $ri) / 2, $color, $r - $ri);
            } else {
                $a2 = $angle + $sweep;
                $large = $sweep > M_PI ? 1 : 0;
                $p = fn ($rad, $a) => sprintf('%.2f %.2f', $cx + $rad * cos($a), $cy + $rad * sin($a));
                $out .= sprintf(
                    '<path d="M %s A %d %d 0 %d 1 %s L %s A %d %d 0 %d 0 %s Z" fill="%s" stroke="#fff" stroke-width="2"/>',
                    $p($r, $angle), $r, $r, $large, $p($r, $a2), $p($ri, $a2), $ri, $ri, $large, $p($ri, $angle), $color,
                );
            }
            $angle += $sweep;

            $ly = 70 + $i * 52;
            $pct = round($row['value'] / $total * 100);
            $out .= sprintf('<rect x="540" y="%d" width="22" height="22" rx="5" fill="%s"/>', $ly - 17, $color);
            $out .= sprintf('<text x="575" y="%d" font-size="22" fill="#1e293b">%s</text>', $ly, $this->e(mb_strimwidth($row['label'], 0, 22, '…')));
            $out .= sprintf('<text x="980" y="%d" font-size="20" text-anchor="end" fill="#475569">%s · %d%%</text>', $ly, $this->e($money(-$row['value'])), $pct);
        }
        $out .= sprintf('<text x="%d" y="%d" text-anchor="middle" font-size="18" fill="#64748b">Total</text>', $cx, $cy - 8);
        $out .= sprintf('<text x="%d" y="%d" text-anchor="middle" font-size="24" font-weight="700" fill="#0f172a">%s</text>', $cx, $cy + 24, $this->e($money($expenses)));

        return $out;
    }

    /**
     * @param list<array{label: string, value: float}> $rows
     */
    private function svgHorizontalBars(array $rows, callable $money): string
    {
        $max = $rows === [] ? 0 : max(array_column($rows, 'value'));
        if ($max <= 0) {
            return $this->emptySvg();
        }
        $out = '';
        $rowH = min(60, intdiv(500, max(1, count($rows))));
        foreach ($rows as $i => $row) {
            $y = 20 + $i * $rowH;
            $w = max(2, ($row['value'] / $max) * 520);
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $out .= sprintf('<text x="250" y="%d" font-size="20" text-anchor="end" fill="#1e293b">%s</text>', $y + $rowH / 2 + 6, $this->e(mb_strimwidth($row['label'], 0, 22, '…')));
            $out .= sprintf('<rect x="265" y="%d" width="%.1f" height="%d" rx="6" fill="%s"/>', $y + 8, $w, $rowH - 16, $color);
            $out .= sprintf('<text x="%.1f" y="%d" font-size="18" fill="#475569">%s</text>', 275 + $w, $y + $rowH / 2 + 6, $this->e($money(-$row['value'])));
        }

        return $out;
    }

    /**
     * Paired horizontal bars per tag: current period (colored) vs previous (grey).
     *
     * @param list<array{label: string, value: float, previous: float}> $rows
     */
    private function svgCompareBars(array $rows, callable $money, string $currentLabel, string $previousLabel): string
    {
        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, $row['value'], $row['previous']);
        }
        if ($max <= 0) {
            return $this->emptySvg();
        }
        $out = '';
        $rowH = min(56, intdiv(470, max(1, count($rows))));
        $barH = max(6, intdiv($rowH - 12, 2));
        foreach ($rows as $i => $row) {
            $y = 10 + $i * $rowH;
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $wc = $row['value'] > 0 ? max(2, ($row['value'] / $max) * 470) : 0;
            $wp = $row['previous'] > 0 ? max(2, ($row['previous'] / $max) * 470) : 0;
            $out .= sprintf('<text x="250" y="%d" font-size="19" text-anchor="end" fill="#1e293b">%s</text>', $y + $barH + 6, $this->e(mb_strimwidth($row['label'], 0, 22, '…')));
            $out .= sprintf('<rect x="265" y="%d" width="%.1f" height="%d" rx="4" fill="%s"/>', $y + 2, $wc, $barH, $color);
            $out .= sprintf('<rect x="265" y="%d" width="%.1f" height="%d" rx="4" fill="#cbd5e1"/>', $y + 4 + $barH, $wp, $barH);
            $out .= sprintf('<text x="%.1f" y="%d" font-size="15" fill="#334155">%s</text>', 273 + $wc, $y + $barH, $this->e($money(-$row['value'])));
            $out .= sprintf('<text x="%.1f" y="%d" font-size="15" fill="#94a3b8">%s</text>', 273 + $wp, $y + 2 + 2 * $barH, $this->e($money(-$row['previous'])));
        }
        $out .= '<rect x="265" y="500" width="16" height="16" rx="3" fill="#6366f1"/><text x="289" y="514" font-size="17" fill="#334155">' . $this->e(mb_strimwidth($currentLabel, 0, 30, '…')) . '</text>';
        $out .= '<rect x="600" y="500" width="16" height="16" rx="3" fill="#cbd5e1"/><text x="624" y="514" font-size="17" fill="#334155">' . $this->e(mb_strimwidth($previousLabel, 0, 30, '…')) . '</text>';

        return $out;
    }

    /**
     * @param list<array{label: string, income: float, expenses: float}> $buckets
     */
    private function svgGroupedBars(array $buckets, callable $money): string
    {
        $max = 0.0;
        foreach ($buckets as $b) {
            $max = max($max, $b['income'], $b['expenses']);
        }
        if ($max <= 0) {
            return $this->emptySvg();
        }
        $left = 110; $top = 20; $w = 870; $h = 430;
        $out = $this->yAxis($left, $top, $w, $h, 0, $max, $money);
        $n = count($buckets);
        $slot = $w / max(1, $n);
        $bw = max(1.5, $slot * 0.38);
        $every = (int) max(1, ceil($n / 16));
        foreach ($buckets as $i => $b) {
            $x = $left + $i * $slot + $slot * 0.1;
            $ih = $b['income'] / $max * $h;
            $eh = $b['expenses'] / $max * $h;
            $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2" fill="#10b981"/>', $x, $top + $h - $ih, $bw, $ih);
            $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2" fill="#ef4444"/>', $x + $bw, $top + $h - $eh, $bw, $eh);
            if ($i % $every === 0) {
                $out .= sprintf('<text x="%.1f" y="%d" font-size="15" text-anchor="middle" fill="#64748b">%s</text>', $x + $bw, $top + $h + 24, $this->e($b['label']));
            }
        }
        $out .= '<rect x="110" y="490" width="16" height="16" rx="3" fill="#10b981"/><text x="134" y="504" font-size="18" fill="#334155">Income</text>';
        $out .= '<rect x="230" y="490" width="16" height="16" rx="3" fill="#ef4444"/><text x="254" y="504" font-size="18" fill="#334155">Expenses</text>';

        return $out;
    }

    /**
     * @param list<array{label: string, value: float}> $points
     */
    private function svgLine(array $points, callable $money): string
    {
        if ($points === []) {
            return $this->emptySvg();
        }
        $values = array_column($points, 'value');
        $min = min($values); $max = max($values);
        if ($max - $min < 1e-9) {
            $min -= 1; $max += 1;
        }
        $pad = ($max - $min) * 0.08;
        $min -= $pad; $max += $pad;
        $left = 130; $top = 20; $w = 850; $h = 450;
        $out = $this->yAxis($left, $top, $w, $h, $min, $max, $money);
        $n = count($points);
        $coords = [];
        foreach ($points as $i => $p) {
            $x = $left + ($n === 1 ? $w / 2 : $i / ($n - 1) * $w);
            $y = $top + $h - ($p['value'] - $min) / ($max - $min) * $h;
            $coords[] = sprintf('%.1f,%.1f', $x, $y);
        }
        $first = explode(',', $coords[0]); $last = explode(',', $coords[$n - 1]);
        $out .= sprintf('<polygon points="%s,%d %s %s,%d" fill="#6366f1" fill-opacity="0.12"/>', $first[0], $top + $h, implode(' ', $coords), $last[0], $top + $h);
        $out .= sprintf('<polyline points="%s" fill="none" stroke="#6366f1" stroke-width="3" stroke-linejoin="round"/>', implode(' ', $coords));
        if ($min < 0 && $max > 0) {
            $zy = $top + $h - (0 - $min) / ($max - $min) * $h;
            $out .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#ef4444" stroke-dasharray="6 4"/>', $left, $left + $w, $zy, $zy);
        }
        $every = (int) max(1, ceil($n / 10));
        foreach ($points as $i => $p) {
            if ($i % $every === 0 || $i === $n - 1) {
                $x = $left + ($n === 1 ? $w / 2 : $i / ($n - 1) * $w);
                $out .= sprintf('<text x="%.1f" y="%d" font-size="15" text-anchor="middle" fill="#64748b">%s</text>', $x, $top + $h + 24, $this->e($p['label']));
            }
        }

        return $out;
    }

    private function yAxis(int $left, int $top, int $w, int $h, float $min, float $max, callable $money): string
    {
        $out = '';
        for ($i = 0; $i <= 4; $i++) {
            $v = $min + ($max - $min) * $i / 4;
            $y = $top + $h - $h * $i / 4;
            $out .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#e2e8f0"/>', $left, $left + $w, $y, $y);
            $out .= sprintf('<text x="%d" y="%.1f" font-size="15" text-anchor="end" fill="#64748b">%s</text>', $left - 10, $y + 5, $this->e($money($v)));
        }

        return $out;
    }

    private function page(string $title, string $subtitle, string $svgBody): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . "html,body{margin:0;padding:0;background:#fff;width:{$w}px;height:{$h}px;overflow:hidden;"
            . 'font-family:"Inter","Noto Sans","DejaVu Sans",Arial,sans-serif,"Noto Color Emoji";}'
            . '.h{padding:22px 28px 0;}.t{font-size:28px;font-weight:700;color:#0f172a}.s{font-size:17px;color:#64748b;margin-top:4px}'
            . '</style></head><body><div class="h"><div class="t">' . $this->e($title) . '</div><div class="s">'
            . $this->e($subtitle) . ' · FundsFlow</div></div>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . ($h - 90) . '" viewBox="0 0 1000 530" font-family="inherit">'
            . $svgBody . '</svg></body></html>';
    }
}
