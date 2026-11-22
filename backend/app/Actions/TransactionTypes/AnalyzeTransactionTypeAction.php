<?php

namespace App\Actions\TransactionTypes;

use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\FieldValueParser;
use App\Support\JsonPath;
use App\Support\PeriodRange;
use App\Support\SelectOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Totals and numeric field aggregates for one of the user's types, with optional
 * field filters and grouping by month or a field. Shared by the assistant tools
 * and the MCP / HTTP endpoint.
 */
class AnalyzeTransactionTypeAction
{
    public const MAX_GROUPS = 50;

    private const NUMERIC = ['number', 'rating', 'duration', 'computed'];

    /**
     * @param array<string, mixed> $args type (name or id), optional filters, period or from/to, group_by
     * @return array<string, mixed>
     */
    public function execute(User $user, array $args): array
    {
        $type = $this->findType($user, $args['type'] ?? $args['transaction_type_id'] ?? null);
        $this->assertPeriod($args);
        $range = PeriodRange::resolve($user, $args);
        $filters = $this->normalizeFilters($args['filters'] ?? null);
        $groupBy = $this->resolveGroupBy($type, $args['group_by'] ?? $args['group'] ?? null);
        $numeric = $this->numericFields($type);

        $query = $this->base($user, $type)
            ->onDays($range->from->toDateString(), $range->to->toDateString());
        $applied = $this->applyFilters($query, $type, $filters);

        $totals = $this->aggregate($query, $numeric);

        $groups = [];
        $truncated = false;

        if ($groupBy !== 'none') {
            [$groups, $truncated] = $this->groups($query, $type, $numeric, $groupBy);
        }

        return [
            'type' => [
                'id' => (int) $type->id,
                'name' => $type->name,
                'emoji' => $type->emoji,
                'archived' => $type->trashed(),
            ],
            'period' => [
                'from' => $range->from->toDateString(),
                'to' => $range->to->toDateString(),
                'label' => $range->label,
                'preset' => $range->preset,
            ],
            'filters' => $applied,
            'group_by' => $groupBy,
            'count' => $totals['count'],
            'total' => $totals['total'],
            'avg' => $totals['avg'],
            'fields' => $totals['fields'],
            'groups' => $groups,
            'truncated' => $truncated,
        ];
    }

    /**
     * Field-value filters (key or label = value) on a transactions query; also used by
     * Analytics "My cards" (App\Actions\Dashboard\DashboardCardData). Returns the applied canonical values.
     *
     * @param Builder<Transaction> $query
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function applyFilters(Builder $query, TransactionType $type, array $filters): array
    {
        $applied = [];

        foreach ($filters as $ref => $value) {
            $field = $this->fieldByRef($type, (string) $ref);

            if ($field === null) {
                $keys = implode(', ', $this->fieldKeys($type));

                throw ValidationException::withMessages([
                    'filters' => 'Unknown field "' . $ref . '".' . ($keys !== '' ? ' Fields: ' . $keys . '.' : ''),
                ]);
            }

            $applied[(string) $field['key']] = $this->applyFilter($query, $field, $value);
        }

        return $applied;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function assertPeriod(array $args): void
    {
        if (PeriodRange::isDate($args['from'] ?? null) || PeriodRange::isDate($args['to'] ?? null)) {
            return;
        }

        $period = $args['period'] ?? null;

        if ($period === null || $period === '') {
            return;
        }

        if (!in_array($period, PeriodRange::PRESETS, true)) {
            throw ValidationException::withMessages([
                'period' => 'Unknown period "' . $period . '". Use ' . implode(', ', PeriodRange::PRESETS) . ' or from/to (YYYY-MM-DD).',
            ]);
        }
    }

    private function findType(User $user, mixed $ref): TransactionType
    {
        $ref = trim((string) $ref);

        if ($ref === '') {
            throw ValidationException::withMessages(['type' => 'Type is required!']);
        }

        $types = $user->transactionTypes()->withTrashed()->orderBy('name')->get();
        $matches = is_numeric($ref) && (int) $ref > 0
            ? $types->filter(fn (TransactionType $type): bool => (int) $type->id === (int) $ref)
            : $types->filter(fn (TransactionType $type): bool => mb_strtolower($type->name) === mb_strtolower($ref)
                || mb_strtolower($type->label()) === mb_strtolower($ref));
        $type = $matches->first(fn (TransactionType $type): bool => !$type->trashed()) ?? $matches->first();

        if (!$type instanceof TransactionType) {
            $names = $types->pluck('name')->implode(', ');

            throw ValidationException::withMessages([
                'type' => 'Unknown type "' . $ref . '".' . ($names !== '' ? ' Your types: ' . $names . '.' : ''),
            ]);
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $value) {
            if (is_int($key) && is_array($value) && (isset($value['key']) || isset($value['field']))) {
                $key = $value['key'] ?? $value['field'];
                $value = $value['value'] ?? null;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function resolveGroupBy(TransactionType $type, mixed $raw): string
    {
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '' || strcasecmp($raw, 'none') === 0) {
            return 'none';
        }

        if (strcasecmp($raw, 'month') === 0) {
            return 'month';
        }

        $field = $this->fieldByRef($type, $raw);

        if ($field === null) {
            $keys = implode(', ', $this->fieldKeys($type));

            throw ValidationException::withMessages([
                'group_by' => 'Unknown group_by "' . $raw . '". Use none, month, or a field key' . ($keys !== '' ? ' (' . $keys . ')' : '') . '.',
            ]);
        }

        return (string) $field['key'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function numericFields(TransactionType $type): array
    {
        return array_values(array_filter(
            $this->schema($type),
            static fn (array $field): bool => in_array($field['type'] ?? '', self::NUMERIC, true),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schema(TransactionType $type): array
    {
        return array_values(array_filter(
            is_array($type->fields) ? $type->fields : [],
            static fn ($field): bool => is_array($field) && isset($field['key']),
        ));
    }

    /**
     * @return list<string>
     */
    private function fieldKeys(TransactionType $type): array
    {
        return array_map(static fn (array $field): string => (string) $field['key'], $this->schema($type));
    }

    /**
     * @return null|array<string, mixed>
     */
    private function fieldByRef(TransactionType $type, string $ref): ?array
    {
        $needle = mb_strtolower(trim($ref));

        foreach ($this->schema($type) as $field) {
            if (mb_strtolower((string) $field['key']) === $needle || mb_strtolower((string) ($field['label'] ?? '')) === $needle) {
                return $field;
            }
        }

        return null;
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
     * @param Builder<Transaction> $query
     * @param array<string, mixed> $field
     */
    private function applyFilter(Builder $query, array $field, mixed $raw): mixed
    {
        $key = (string) $field['key'];
        $kind = (string) ($field['type'] ?? 'text');

        if ($kind === 'boolean') {
            $bool = FieldValueParser::boolean($raw);

            if ($bool === null) {
                throw ValidationException::withMessages(['filters' => ($field['label'] ?? $key) . ' must be yes or no!']);
            }

            $expr = JsonPath::text('data', $key);
            $truthy = "LOWER(TRIM(CAST({$expr} AS CHAR))) IN ('1', 'true', 'yes')";
            $query->whereRaw($bool ? $truthy : "({$expr} IS NOT NULL AND NOT ({$truthy}))");

            return $bool;
        }

        if (in_array($kind, self::NUMERIC, true)) {
            if (is_string($raw)) {
                $raw = str_replace(',', '.', trim($raw));
            }

            if (!is_numeric($raw)) {
                throw ValidationException::withMessages(['filters' => ($field['label'] ?? $key) . ' must be a number!']);
            }

            $number = (float) $raw;
            $expr = JsonPath::number('data', $key);
            $query->whereRaw("{$expr} IS NOT NULL AND ABS({$expr} - ?) < 0.00005", [$number]);

            return $number;
        }

        if ($kind === 'multiselect') {
            $values = is_array($raw) ? $raw : SelectOptions::split($raw);
            $matched = [];

            foreach ($values as $one) {
                $hit = SelectOptions::match($field['options'] ?? [], $one) ?? trim((string) $one);

                if ($hit === '') {
                    continue;
                }

                $matched[] = $hit;
                $expr = JsonPath::text('data', $key);
                $needle = mb_strtolower($hit);
                $like = '%"' . addcslashes($needle, '%_\\') . '"%';
                $query->whereRaw("LOWER({$expr}) = ? OR LOWER({$expr}) LIKE ? ESCAPE '\\'", [$needle, $like]);
            }

            if ($matched === []) {
                throw ValidationException::withMessages(['filters' => ($field['label'] ?? $key) . ' needs at least one value!']);
            }

            return count($matched) === 1 ? $matched[0] : $matched;
        }

        $text = trim(is_scalar($raw) ? (string) $raw : '');

        if ($kind === 'select') {
            $text = SelectOptions::match($field['options'] ?? [], $text) ?? $text;
        }

        if ($text === '') {
            throw ValidationException::withMessages(['filters' => ($field['label'] ?? $key) . ' needs a value!']);
        }

        $expr = JsonPath::text('data', $key);
        $query->whereRaw("LOWER({$expr}) = ?", [mb_strtolower($text)]);

        return $text;
    }

    /**
     * @param Builder<Transaction> $query
     * @param list<array<string, mixed>> $numeric
     * @return array{count: int, total: float, avg: float, fields: array<string, array<string, mixed>>}
     */
    private function aggregate(Builder $query, array $numeric): array
    {
        $row = $this->selectAggregates($query->clone(), $numeric)->first();

        return $this->rowStats($row, $numeric);
    }

    /**
     * @param Builder<Transaction> $query
     * @param list<array<string, mixed>> $numeric
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function groups(Builder $query, TransactionType $type, array $numeric, string $groupBy): array
    {
        if ($groupBy === 'month') {
            $expr = JsonPath::yearMonth('at');
            $rows = $this->selectAggregates($query->clone()->selectRaw("{$expr} as bucket"), $numeric)
                ->groupByRaw($expr)
                ->orderByRaw($expr)
                ->get();

            $groups = [];

            foreach ($rows as $row) {
                $month = (string) ($row->bucket ?? '');
                $stats = $this->rowStats($row, $numeric);
                $groups[] = [
                    'value' => $month,
                    'label' => $month,
                    ...$stats,
                ];
            }

            return [$groups, false];
        }

        $field = $this->fieldByRef($type, $groupBy);
        $expr = JsonPath::text('data', (string) $field['key']);
        $rows = $this->selectAggregates($query->clone()->selectRaw("{$expr} as bucket"), $numeric)
            ->groupByRaw($expr)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $raw = $row->bucket;
            $empty = $raw === null || $raw === '';
            $value = $empty ? null : $this->normalizeGroupValue($raw, $field);
            $stats = $this->rowStats($row, $numeric);
            $groups[] = [
                'value' => $value,
                'label' => $empty ? 'Not set' : $this->groupLabel($value, $field),
                ...$stats,
            ];
        }

        usort($groups, function (array $a, array $b): int {
            $aEmpty = $a['value'] === null || $a['value'] === '';
            $bEmpty = $b['value'] === null || $b['value'] === '';

            if ($aEmpty !== $bEmpty) {
                return $aEmpty ? 1 : -1;
            }

            $byTotal = abs($b['total']) <=> abs($a['total']);

            return $byTotal !== 0 ? $byTotal : $b['count'] <=> $a['count'];
        });

        $truncated = count($groups) > self::MAX_GROUPS;

        if (!$truncated) {
            return [$groups, false];
        }

        $head = array_slice($groups, 0, self::MAX_GROUPS);
        $tail = array_slice($groups, self::MAX_GROUPS);
        $other = $this->mergeGroups($tail, $numeric);
        $head[] = [
            'value' => null,
            'label' => 'Other',
            ...$other,
        ];

        return [$head, true];
    }

    /**
     * @param Builder<Transaction> $query
     * @param list<array<string, mixed>> $numeric
     * @return Builder<Transaction>
     */
    private function selectAggregates(Builder $query, array $numeric): Builder
    {
        $query->selectRaw('COUNT(*) as c')
            ->selectRaw('COALESCE(SUM(amount), 0) as total');

        foreach ($numeric as $field) {
            $alias = $this->alias((string) $field['key']);
            $expr = JsonPath::number('data', (string) $field['key']);
            $query->selectRaw("COUNT({$expr}) as {$alias}_c")
                ->selectRaw("COALESCE(SUM({$expr}), 0) as {$alias}_sum")
                ->selectRaw("AVG({$expr}) as {$alias}_avg")
                ->selectRaw("MIN({$expr}) as {$alias}_min")
                ->selectRaw("MAX({$expr}) as {$alias}_max");
        }

        return $query;
    }

    /**
     * @param list<array<string, mixed>> $numeric
     * @return array{count: int, total: float, avg: float, fields: array<string, array<string, mixed>>}
     */
    private function rowStats(?object $row, array $numeric): array
    {
        $count = (int) ($row->c ?? 0);
        $total = $this->money($row->total ?? 0);

        return [
            'count' => $count,
            'total' => $total,
            'avg' => $count > 0 ? $this->money($total / $count) : 0.0,
            'fields' => $this->fieldStats($row, $numeric),
        ];
    }

    /**
     * @param list<array<string, mixed>> $numeric
     * @return array<string, array<string, mixed>>
     */
    private function fieldStats(?object $row, array $numeric): array
    {
        $out = [];

        foreach ($numeric as $field) {
            $alias = $this->alias((string) $field['key']);
            $count = (int) ($row->{$alias . '_c'} ?? 0);
            $sum = $this->qty($row->{$alias . '_sum'} ?? 0);

            $out[(string) $field['key']] = [
                'key' => (string) $field['key'],
                'label' => (string) ($field['label'] ?? $field['key']),
                'kind' => (string) ($field['type'] ?? 'number'),
                'unit' => $field['unit'] ?? null,
                'count' => $count,
                'sum' => $sum,
                'avg' => $count > 0 ? $this->qty($row->{$alias . '_avg'} ?? ($sum / $count)) : null,
                'min' => $count > 0 ? $this->qty($row->{$alias . '_min'} ?? 0) : null,
                'max' => $count > 0 ? $this->qty($row->{$alias . '_max'} ?? 0) : null,
            ];
        }

        return $out;
    }

    /**
     * Overflow groups collapsed into one Other bucket (weighted average for field avgs).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $numeric
     * @return array{count: int, total: float, avg: float, fields: array<string, array<string, mixed>>}
     */
    private function mergeGroups(array $rows, array $numeric): array
    {
        $count = array_sum(array_column($rows, 'count'));
        $total = $this->money(array_sum(array_column($rows, 'total')));
        $fields = [];

        foreach ($numeric as $field) {
            $key = (string) $field['key'];
            $n = 0;
            $sum = 0.0;
            $min = null;
            $max = null;

            foreach ($rows as $row) {
                $stats = $row['fields'][$key] ?? null;

                if (!is_array($stats)) {
                    continue;
                }

                $n += (int) $stats['count'];
                $sum += (float) $stats['sum'];

                if ($stats['min'] !== null) {
                    $min = $min === null ? (float) $stats['min'] : min($min, (float) $stats['min']);
                }

                if ($stats['max'] !== null) {
                    $max = $max === null ? (float) $stats['max'] : max($max, (float) $stats['max']);
                }
            }

            $fields[$key] = [
                'key' => $key,
                'label' => (string) ($field['label'] ?? $key),
                'kind' => (string) ($field['type'] ?? 'number'),
                'unit' => $field['unit'] ?? null,
                'count' => $n,
                'sum' => $this->qty($sum),
                'avg' => $n > 0 ? $this->qty($sum / $n) : null,
                'min' => $min === null ? null : $this->qty($min),
                'max' => $max === null ? null : $this->qty($max),
            ];
        }

        return [
            'count' => $count,
            'total' => $total,
            'avg' => $count > 0 ? $this->money($total / $count) : 0.0,
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function normalizeGroupValue(mixed $raw, array $field): mixed
    {
        if (($field['type'] ?? '') === 'boolean') {
            return $this->isTruthy($raw);
        }

        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function groupLabel(mixed $value, array $field): string
    {
        if (($field['type'] ?? '') === 'boolean') {
            return $value === true || $value === 'true' || $value === 1 || $value === '1' ? 'Yes' : 'No';
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

    private function alias(string $key): string
    {
        return 'f_' . $key;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function qty(mixed $value): float
    {
        return round((float) $value, 4);
    }
}
