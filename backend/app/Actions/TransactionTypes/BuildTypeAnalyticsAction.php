<?php

namespace App\Actions\TransactionTypes;

use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\JsonPath;
use App\Support\PeriodRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Analytics for one of the user's types: period totals, top field values,
 * numeric trends over the last 6 months, and a spend breakdown by a groupable field.
 */
class BuildTypeAnalyticsAction
{
    public const TOP_DEFAULT = 5;

    public const TOP_MIN = 3;

    public const TOP_MAX = 10;

    public const TREND_MONTHS = 6;

    private const GROUPABLE = ['select', 'text', 'boolean'];

    private const NUMERIC = ['number', 'rating', 'duration'];

    /**
     * @param array<string, mixed> $args period (preset) or from/to (YYYY-MM-DD), optional group and top
     * @return array<string, mixed>
     */
    public function execute(User $user, TransactionType $type, array $args): array
    {
        $range = PeriodRange::resolve($user, $args);
        $topN = $this->topN($args['top'] ?? null);
        $fields = $type->visibleFields();
        $groupable = $this->fieldsOf($fields, self::GROUPABLE);
        $numeric = $this->fieldsOf($fields, self::NUMERIC);

        $current = $this->stats($user, $type, $range->from->toDateString(), $range->to->toDateString());
        $previous = $this->stats($user, $type, $range->previousFrom->toDateString(), $range->previousToDate()->toDateString());

        $groupKey = $this->pickGroup($groupable, isset($args['group']) ? (string) $args['group'] : null);
        $months = $this->monthKeys($range);

        return [
            'type' => [
                'id' => (int) $type->id,
                'name' => $type->name,
                'emoji' => $type->emoji,
                'color' => $type->color,
                'archived' => $type->trashed(),
            ],
            'period' => [
                'from' => $range->from->toDateString(),
                'to' => $range->to->toDateString(),
                'label' => $range->label,
                'previous_from' => $range->previousFrom->toDateString(),
                'previous_to' => $range->previousToDate()->toDateString(),
                'previous_label' => $range->previousLabel,
            ],
            'count' => $current['count'],
            'total' => $current['total'],
            'avg' => $current['avg'],
            'previous' => $previous,
            'delta' => $this->delta($current, $previous),
            'top_values' => array_map(
                fn (array $field): array => $this->topValues($user, $type, $range, $field, $topN),
                $groupable,
            ),
            'trends' => array_map(
                fn (array $field): array => $this->trend($user, $type, $months, $range, $field),
                $numeric,
            ),
            'groupable' => array_map(fn (array $field): array => $this->fieldMeta($field), $groupable),
            'breakdown' => $groupKey === null
                ? null
                : $this->breakdown($user, $type, $range, $months, $this->fieldByKey($groupable, $groupKey), $topN),
        ];
    }

    /**
     * @param array<string, float|int> $current
     * @param array<string, float|int> $previous
     * @return array{count: int, count_pct: null|float, total: float, total_pct: null|float, avg: float, avg_pct: null|float}
     */
    private function delta(array $current, array $previous): array
    {
        return [
            'count' => $current['count'] - $previous['count'],
            'count_pct' => $this->pct($current['count'] - $previous['count'], $previous['count']),
            'total' => $this->money($current['total'] - $previous['total']),
            'total_pct' => $this->pct($current['total'] - $previous['total'], $previous['total']),
            'avg' => $this->money($current['avg'] - $previous['avg']),
            'avg_pct' => $this->pct($current['avg'] - $previous['avg'], $previous['avg']),
        ];
    }

    /**
     * @return array{count: int, total: float, avg: float}
     */
    private function stats(User $user, TransactionType $type, string $from, string $to): array
    {
        $row = $this->base($user, $type)
            ->onDays($from, $to)
            ->selectRaw('COUNT(*) as c')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();

        $count = (int) ($row->c ?? 0);
        $total = $this->money($row->total ?? 0);

        return [
            'count' => $count,
            'total' => $total,
            'avg' => $count > 0 ? $this->money($total / $count) : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function topValues(User $user, TransactionType $type, PeriodRange $range, array $field, int $topN): array
    {
        $rows = $this->groupByField(
            $user,
            $type,
            $range->from->toDateString(),
            $range->to->toDateString(),
            (string) $field['key'],
        );

        $collapsed = $this->collapseTop($rows, $topN, $field);

        return [
            ...$this->fieldMeta($field),
            'rows' => $this->withUnusedOptions($collapsed, $field),
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function trend(User $user, TransactionType $type, array $months, PeriodRange $range, array $field): array
    {
        $expr = JsonPath::number('data', (string) $field['key']);
        $monthExpr = JsonPath::yearMonth('at');
        $from = $months[0] . '-01';
        $to = $range->to->toDateString();

        $found = $this->base($user, $type)
            ->onDays($from, $to)
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw("COUNT({$expr}) as c")
            ->selectRaw("COALESCE(SUM({$expr}), 0) as sum_value")
            ->selectRaw("AVG({$expr}) as avg_value")
            ->whereRaw("{$expr} IS NOT NULL")
            ->groupByRaw($monthExpr)
            ->get()
            ->keyBy('month');

        $series = [];

        foreach ($months as $month) {
            $row = $found->get($month);
            $count = (int) ($row->c ?? 0);
            $sum = $this->qty($row->sum_value ?? 0);
            $series[] = [
                'month' => $month,
                'sum' => $sum,
                'avg' => $count > 0 ? $this->qty($row->avg_value ?? ($sum / $count)) : 0.0,
                'count' => $count,
            ];
        }

        return [
            ...$this->fieldMeta($field),
            'months' => $series,
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string> $months
     * @return array<string, mixed>
     */
    private function breakdown(User $user, TransactionType $type, PeriodRange $range, array $months, array $field, int $topN): array
    {
        $from = $months[0] . '-01';
        $to = $range->to->toDateString();
        $window = $this->groupByFieldMonth($user, $type, $from, $to, (string) $field['key']);
        $periodRows = $this->groupByField($user, $type, $range->from->toDateString(), $range->to->toDateString(), (string) $field['key']);

        // Top N is decided on the current period so the pie and the stacked bars share labels.
        $period = $this->collapseTop($periodRows, $topN, $field);
        $leaders = array_values(array_filter(
            array_column($period, 'value'),
            static fn ($value): bool => $value !== null,
        ));

        $byMonth = [];

        foreach ($months as $month) {
            $sliceRows = $window->get($month, collect());
            $byMonth[] = [
                'month' => $month,
                'slices' => $this->alignSlices($sliceRows, $leaders, $field),
            ];
        }

        return [
            ...$this->fieldMeta($field),
            'period' => $period,
            'months' => $byMonth,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function groupByField(User $user, TransactionType $type, string $from, string $to, string $key): Collection
    {
        $expr = JsonPath::text('data', $key);

        return $this->base($user, $type)
            ->onDays($from, $to)
            ->selectRaw("{$expr} as value")
            ->selectRaw('COUNT(*) as c')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupByRaw($expr)
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, object>>
     */
    private function groupByFieldMonth(User $user, TransactionType $type, string $from, string $to, string $key): Collection
    {
        $expr = JsonPath::text('data', $key);
        $monthExpr = JsonPath::yearMonth('at');

        return $this->base($user, $type)
            ->onDays($from, $to)
            ->selectRaw("{$monthExpr} as month")
            ->selectRaw("{$expr} as value")
            ->selectRaw('COUNT(*) as c')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupByRaw("{$monthExpr}, {$expr}")
            ->get()
            ->groupBy('month');
    }

    /**
     * @param Collection<int, object> $rows
     * @param array<string, mixed> $field
     * @return list<array{value: mixed, label: string, count: int, total: float, share: float}>
     */
    private function collapseTop(Collection $rows, int $topN, array $field): array
    {
        $items = $rows->map(fn ($row): array => $this->namedRow($row, $field))
            ->groupBy(fn (array $row): string => $this->mergeKey($row['value']))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $first['count'] = (int) $group->sum('count');
                $first['total'] = $this->money($group->sum('total'));

                return $first;
            })
            ->sortByDesc(fn (array $row): float => abs($row['total']))
            ->values();

        if ($items->count() <= $topN) {
            return $this->withShare($items->all());
        }

        $head = $items->take($topN)->all();
        $rest = $items->slice($topN);
        $head[] = [
            'value' => null,
            'label' => 'Other',
            'others' => $rest->pluck('label')->values()->all(),
            'count' => (int) $rest->sum('count'),
            'total' => $this->money($rest->sum('total')),
        ];

        return $this->withShare($head);
    }

    /**
     * @param Collection<int, object> $rows
     * @param list<mixed> $leaders
     * @param array<string, mixed> $field
     * @return list<array{value: mixed, label: string, count: int, total: float}>
     */
    private function alignSlices(Collection $rows, array $leaders, array $field): array
    {
        $named = $rows->map(fn ($row): array => $this->namedRow($row, $field));
        $slices = [];

        foreach ($leaders as $value) {
            $match = $named->first(fn (array $row): bool => $row['value'] === $value)
                ?? ['value' => $value, 'label' => $this->labelValue($value, $field), 'count' => 0, 'total' => 0.0];
            $slices[] = $match;
        }
        $rest = $named->filter(fn (array $row): bool => !in_array($row['value'], $leaders, true));
        $slices[] = [
            'value' => null,
            'label' => 'Other',
            'count' => (int) $rest->sum('count'),
            'total' => $this->money($rest->sum('total')),
        ];

        return $slices;
    }

    /**
     * @param array<string, mixed> $field
     * @return array{value: mixed, label: string, count: int, total: float}
     */
    private function namedRow(object $row, array $field): array
    {
        $raw = $row->value;
        $empty = $raw === null || $raw === '';
        $value = $empty ? '' : $this->normalizeValue($raw, $field);

        return [
            'value' => $empty ? '' : $value,
            'label' => $empty ? 'Not set' : $this->labelValue($value, $field),
            'count' => (int) $row->c,
            'total' => $this->money($row->total),
        ];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function normalizeValue(mixed $raw, array $field): mixed
    {
        if (($field['type'] ?? '') === 'boolean') {
            return $this->isTruthy($raw);
        }

        if (is_string($raw)) {
            return $raw;
        }

        if (is_bool($raw) || is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }

        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function labelValue(mixed $value, array $field): string
    {
        if (($field['type'] ?? '') === 'boolean') {
            return $value === true || $value === 'true' || $value === 1 || $value === '1' ? 'Yes' : 'No';
        }

        if ($value === '' || $value === null) {
            return 'Not set';
        }

        return (string) $value;
    }

    private function isTruthy(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }

        return in_array(strtolower(trim((string) $raw)), ['true', 'yes', '1'], true);
    }

    /**
     * @param list<array{value: mixed, label: string, count: int, total: float}> $rows
     * @return list<array{value: mixed, label: string, count: int, total: float, share: float}>
     */
    private function withShare(array $rows): array
    {
        $abs = array_sum(array_map(fn (array $row): float => abs($row['total']), $rows));
        $out = [];

        foreach ($rows as $row) {
            $row['share'] = $abs > 0 ? round(abs($row['total']) / $abs * 100, 1) : 0.0;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Last 6 calendar months ending at the period's end month.
     *
     * @return list<string>
     */
    private function monthKeys(PeriodRange $range): array
    {
        $end = $range->to->startOfMonth();
        $months = [];

        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $months[] = $end->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $months;
    }

    /**
     * @return Builder<Transaction>
     */
    private function base(User $user, TransactionType $type): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('transaction_type_id', $type->id);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param list<string> $kinds
     * @return list<array<string, mixed>>
     */
    private function fieldsOf(array $fields, array $kinds): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field): bool => in_array($field['type'] ?? '', $kinds, true) && JsonPath::keyIsSafe((string) ($field['key'] ?? '')),
        ));
    }

    /**
     * @param list<array<string, mixed>> $groupable
     */
    private function pickGroup(array $groupable, ?string $requested): ?string
    {
        $keys = array_column($groupable, 'key');

        if ($requested !== null && $requested !== '' && in_array($requested, $keys, true)) {
            return $requested;
        }

        foreach (['station', 'store', 'format', 'service', 'merchant', 'place'] as $preferred) {
            if (in_array($preferred, $keys, true)) {
                return $preferred;
            }
        }

        return $keys[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function fieldByKey(array $fields, string $key): array
    {
        foreach ($fields as $field) {
            if (($field['key'] ?? '') === $key) {
                return $field;
            }
        }

        return $fields[0];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{key: string, label: string, kind: string, unit: ?string}
     */
    private function fieldMeta(array $field): array
    {
        return [
            'key' => (string) $field['key'],
            'label' => (string) ($field['label'] ?? $field['key']),
            'kind' => (string) ($field['type'] ?? ''),
            'unit' => isset($field['unit']) && $field['unit'] !== '' ? (string) $field['unit'] : null,
        ];
    }

    /**
     * Select options that did not appear in the period stay visible at 0, after the ranked rows.
     *
     * @param list<array{value: mixed, label: string, count: int, total: float, share: float}> $rows
     * @param array<string, mixed> $field
     * @return list<array{value: mixed, label: string, count: int, total: float, share: float}>
     */
    private function withUnusedOptions(array $rows, array $field): array
    {
        if (($field['type'] ?? '') !== 'select' || !is_array($field['options'] ?? null)) {
            return $rows;
        }
        $have = [];

        foreach ($rows as $row) {
            $have[(string) $row['label']] = true;

            if (($row['value'] ?? null) !== null && $row['value'] !== '') {
                $have[(string) $row['value']] = true;
            }
        }

        foreach ($field['options'] as $option) {
            $label = (string) $option;

            if ($label === '' || isset($have[$label])) {
                continue;
            }
            $rows[] = [
                'value' => $label,
                'label' => $label,
                'count' => 0,
                'total' => 0.0,
                'share' => 0.0,
            ];
        }

        return $rows;
    }

    private function mergeKey(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }

        if ($value === false || $value === 0 || $value === '0') {
            return '0';
        }

        return (string) $value;
    }

    private function topN(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::TOP_DEFAULT;
        }

        return max(self::TOP_MIN, min(self::TOP_MAX, (int) $raw));
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function qty(mixed $value): float
    {
        return round((float) $value, 4);
    }

    private function pct(float|int $delta, float|int $base): ?float
    {
        if ((float) $base == 0.0) {
            return null;
        }

        return round($delta / abs((float) $base) * 100, 1);
    }
}
