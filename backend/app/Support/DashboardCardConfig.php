<?php

namespace App\Support;

use App\Models\Tag;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The one validator / normalizer for Analytics "My cards" configs, shared by the web API,
 * the assistant tools (web + Telegram) and MCP. Accepts friendly input (type and tag names,
 * field labels, "tags" for split) and returns the stored shape:
 *
 *   title    ?string           own title (null = auto label)
 *   type_id  ?int              transaction type filter
 *   tag_ids  list<int>         tag filter (child tags included when aggregating)
 *   filters  array<key, value> field-value filters, e.g. {station: "WOG"} (needs a type)
 *   flow     expense|income|all
 *   metric   amount | <numeric field key> | fields (stat only: every numeric field of the type)
 *   agg      sum|avg|count|min|max
 *   split    null | tag | <field key>   (chart optional, pie required, stat never)
 *   chart    null | line|bar            (chart only)
 */
final class DashboardCardConfig
{
    public const KINDS = ['stat', 'chart', 'pie'];

    public const SIZES = ['S', 'M', 'L'];

    public const DEFAULT_SIZES = ['stat' => 'S', 'chart' => 'M', 'pie' => 'M'];

    public const AGGS = ['sum', 'avg', 'count', 'min', 'max'];

    public const FLOWS = ['expense', 'income', 'all'];

    public const CHARTS = ['line', 'bar'];

    public const MAX_CARDS = 30;

    public const MAX_SERIES = 6;

    public const MAX_TAGS = 10;

    public const MAX_FILTERS = 5;

    public const NUMERIC = ['number', 'rating', 'duration', 'computed'];

    public const SPLITTABLE = ['select', 'text', 'boolean'];

    private const AGG_LABELS = ['sum' => 'Total', 'avg' => 'Average', 'count' => 'Count', 'min' => 'Min', 'max' => 'Max'];

    public static function kind(mixed $raw): string
    {
        $kind = strtolower(trim((string) $raw));
        $kind = ['donut' => 'pie', 'share' => 'pie', 'line' => 'chart', 'bar' => 'chart', 'number' => 'stat'][$kind] ?? $kind;

        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Card kind must be stat, chart or pie!']);
        }

        return $kind;
    }

    public static function size(mixed $raw, string $kind): string
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_SIZES[$kind];
        }

        $size = strtoupper(trim((string) $raw));
        $size = ['SMALL' => 'S', 'MEDIUM' => 'M', 'LARGE' => 'L'][$size] ?? $size;

        if (!in_array($size, self::SIZES, true)) {
            throw ValidationException::withMessages(['size' => 'Size must be S, M or L!']);
        }

        return $size;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(User $user, string $kind, array $input): array
    {
        $type = self::type($user, $input['type_id'] ?? $input['type'] ?? null);
        $filters = self::filters($type, $input['filters'] ?? null);
        $metric = self::metric($type, $kind, $input['metric'] ?? null);
        $split = self::split($type, $kind, $input['split'] ?? $input['split_by'] ?? null);

        $agg = strtolower(trim((string) ($input['agg'] ?? '')));
        $agg = ['average' => 'avg', 'mean' => 'avg', 'total' => 'sum', 'minimum' => 'min', 'maximum' => 'max', 'number' => 'count'][$agg] ?? $agg;

        if ($agg === '') {
            // All fields: each field gets its own default (avg for prices, sum for liters/totals).
            $agg = $metric === 'amount' ? 'sum' : ($metric === 'fields' ? 'auto' : self::defaultFieldAgg($type, $metric));
        }

        if (!in_array($agg, self::AGGS, true) && !($agg === 'auto' && $metric === 'fields')) {
            throw ValidationException::withMessages(['agg' => 'agg must be one of ' . implode(', ', self::AGGS) . '!']);
        }

        $flow = strtolower(trim((string) ($input['flow'] ?? '')));
        $flow = ['expenses' => 'expense', 'spending' => 'expense', 'incomes' => 'income', 'both' => 'all', 'any' => 'all'][$flow] ?? $flow;

        if ($flow === '') {
            $flow = $metric === 'amount' ? 'expense' : 'all';
        }

        if (!in_array($flow, self::FLOWS, true)) {
            throw ValidationException::withMessages(['flow' => 'flow must be expense, income or all!']);
        }

        $chart = null;

        if ($kind === 'chart') {
            $chart = strtolower(trim((string) ($input['chart'] ?? '')));
            $chart = $chart === '' ? (in_array($agg, ['sum', 'count'], true) ? 'bar' : 'line') : $chart;

            if (!in_array($chart, self::CHARTS, true)) {
                throw ValidationException::withMessages(['chart' => 'chart must be line or bar!']);
            }
        }

        $title = trim(preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')) ?? '');

        if (mb_strlen($title) > 60) {
            throw ValidationException::withMessages(['title' => 'Title must be 60 characters or fewer!']);
        }

        return [
            'title' => $title === '' ? null : $title,
            'type_id' => $type ? (int) $type->id : null,
            'tag_ids' => self::tagIds($user, $input['tag_ids'] ?? $input['tags'] ?? []),
            'filters' => $filters,
            'flow' => $flow,
            'metric' => $metric,
            'agg' => $agg,
            'split' => $split,
            'chart' => $chart,
        ];
    }

    /**
     * Readable auto title: "Fuel · WOG · Total amount", "Fuel · Average Price per liter by Station".
     *
     * @param array<string, mixed> $config
     */
    public static function label(User $user, string $kind, array $config, ?TransactionType $type = null): string
    {
        if (($config['title'] ?? null) !== null && $config['title'] !== '') {
            return (string) $config['title'];
        }

        $type ??= $config['type_id'] ? $user->transactionTypes()->withTrashed()->find($config['type_id']) : null;
        $parts = [];

        if ($type) {
            $parts[] = $type->name;
        }

        foreach ($config['filters'] ?? [] as $value) {
            $parts[] = is_bool($value) ? ($value ? 'Yes' : 'No') : (is_array($value) ? implode(', ', $value) : (string) $value);
        }

        if (($config['tag_ids'] ?? []) !== []) {
            $tree = TagTree::for($user);
            $parts[] = implode(', ', array_map(static fn (int $id): string => $tree->meta($id)['title'] ?: '#' . $id, $config['tag_ids']));
        }

        $metric = match ($config['metric'] ?? 'amount') {
            'amount' => ['income' => 'income', 'all' => 'net', 'expense' => 'spending'][$config['flow'] ?? 'expense'] ?? 'amount',
            'fields' => 'fields',
            default => (string) (self::field($type, (string) $config['metric'])['label'] ?? $config['metric']),
        };

        $agg = ($config['agg'] ?? 'sum') === 'count' ? 'Count' : (self::AGG_LABELS[$config['agg'] ?? 'sum'] ?? 'Total') . ' ' . $metric;

        if ($kind === 'pie') {
            $agg = ($config['agg'] ?? 'sum') === 'count' ? 'Count' : ucfirst($metric);
        }

        if ($config['metric'] === 'fields') {
            $agg = 'Fields';
        }

        $text = $agg;

        if (($config['split'] ?? null) !== null) {
            $text .= ' by ' . ($config['split'] === 'tag' ? 'tag' : (string) (self::field($type, (string) $config['split'])['label'] ?? $config['split']));
        }

        $parts[] = $text;

        return mb_substr(implode(' · ', $parts), 0, 80);
    }

    /**
     * @return null|array<string, mixed>
     */
    public static function field(?TransactionType $type, string $ref): ?array
    {
        if (!$type) {
            return null;
        }

        $needle = mb_strtolower(trim($ref));

        foreach (is_array($type->fields) ? $type->fields : [] as $field) {
            if (!is_array($field) || !isset($field['key'])) {
                continue;
            }

            if (mb_strtolower((string) $field['key']) === $needle || mb_strtolower((string) ($field['label'] ?? '')) === $needle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function numericFields(?TransactionType $type): array
    {
        if (!$type) {
            return [];
        }

        return array_values(array_filter(
            $type->visibleFields(),
            static fn (array $field): bool => in_array($field['type'] ?? '', self::NUMERIC, true),
        ));
    }

    public static function defaultFieldAgg(?TransactionType $type, string $metric): string
    {
        $field = self::field($type, $metric);
        $key = mb_strtolower((string) ($field['key'] ?? ''));

        // Prices and ratings read as averages; quantities (liters, km, totals) as sums.
        return ($field['type'] ?? '') === 'rating' || str_contains($key, 'price') || str_contains($key, 'rate') ? 'avg' : 'sum';
    }

    private static function type(User $user, mixed $ref): ?TransactionType
    {
        if ($ref === null || $ref === '' || $ref === 0) {
            return null;
        }

        $ref = trim((string) $ref);
        $types = $user->transactionTypes()->withTrashed()->get();
        $matches = is_numeric($ref)
            ? $types->filter(fn (TransactionType $t): bool => (int) $t->id === (int) $ref)
            : $types->filter(fn (TransactionType $t): bool => mb_strtolower($t->name) === mb_strtolower($ref));
        $type = $matches->first(fn (TransactionType $t): bool => !$t->trashed()) ?? $matches->first();

        if (!$type) {
            $names = $types->reject(fn (TransactionType $t): bool => $t->trashed())->pluck('name')->implode(', ');

            throw ValidationException::withMessages([
                'type' => 'Unknown type "' . $ref . '".' . ($names !== '' ? ' Your types: ' . $names . '.' : ''),
            ]);
        }

        return $type;
    }

    /**
     * @return list<int>
     */
    private static function tagIds(User $user, mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : array_map('trim', explode(',', $raw));
        }

        if (!is_array($raw)) {
            throw ValidationException::withMessages(['tag_ids' => 'Tags must be a list!']);
        }

        $raw = array_values(array_filter($raw, static fn ($v): bool => $v !== null && $v !== ''));

        if (count($raw) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tag_ids' => 'At most ' . self::MAX_TAGS . ' tags per card!']);
        }

        if ($raw === []) {
            return [];
        }

        $tags = $user->tags()->get(['id', 'title']);
        $ids = [];

        foreach ($raw as $ref) {
            $ref = is_array($ref) ? ($ref['id'] ?? $ref['title'] ?? '') : $ref;
            $ref = trim((string) $ref);
            $tag = is_numeric($ref)
                ? $tags->first(fn (Tag $t): bool => (int) $t->id === (int) $ref)
                : $tags->first(fn (Tag $t): bool => mb_strtolower($t->title) === mb_strtolower(ltrim($ref, '#')));

            if (!$tag) {
                throw ValidationException::withMessages(['tag_ids' => 'Unknown tag "' . $ref . '"!']);
            }

            $ids[(int) $tag->id] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private static function filters(?TransactionType $type, mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if ($raw === null || $raw === [] || $raw === '') {
            return [];
        }

        if (!is_array($raw)) {
            throw ValidationException::withMessages(['filters' => 'Filters must be an object of field = value!']);
        }

        $pairs = [];

        foreach ($raw as $key => $value) {
            if (is_int($key) && is_array($value) && (isset($value['key']) || isset($value['field']))) {
                $key = $value['key'] ?? $value['field'];
                $value = $value['value'] ?? null;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $pairs[(string) $key] = $value;
        }

        if ($pairs === []) {
            return [];
        }

        if (!$type) {
            throw ValidationException::withMessages(['filters' => 'Field filters need a transaction type!']);
        }

        if (count($pairs) > self::MAX_FILTERS) {
            throw ValidationException::withMessages(['filters' => 'At most ' . self::MAX_FILTERS . ' field filters!']);
        }

        $out = [];

        foreach ($pairs as $ref => $value) {
            $field = self::field($type, $ref);

            if (!$field || ($field['type'] ?? '') === 'date') {
                $keys = implode(', ', array_map(static fn (array $f): string => (string) $f['key'], $type->visibleFields()));

                throw ValidationException::withMessages(['filters' => 'Unknown field "' . $ref . '" for ' . $type->name . '.' . ($keys !== '' ? ' Fields: ' . $keys . '.' : '')]);
            }

            $out[(string) $field['key']] = self::filterValue($field, $value);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function filterValue(array $field, mixed $raw): mixed
    {
        $label = (string) ($field['label'] ?? $field['key']);
        $kind = (string) ($field['type'] ?? 'text');

        if ($kind === 'boolean') {
            $bool = FieldValueParser::boolean($raw);

            if ($bool === null) {
                throw ValidationException::withMessages(['filters' => $label . ' must be yes or no!']);
            }

            return $bool;
        }

        if (in_array($kind, self::NUMERIC, true)) {
            $num = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;

            if (!is_numeric($num)) {
                throw ValidationException::withMessages(['filters' => $label . ' must be a number!']);
            }

            return (float) $num;
        }

        if (is_array($raw)) {
            $raw = reset($raw);
        }

        $text = trim(is_scalar($raw) ? (string) $raw : '');

        if ($text === '' || mb_strlen($text) > 100) {
            throw ValidationException::withMessages(['filters' => $label . ' needs a value (up to 100 characters)!']);
        }

        if (in_array($kind, ['select', 'multiselect'], true)) {
            $match = SelectOptions::match($field['options'] ?? [], $text);

            if ($match === null) {
                throw ValidationException::withMessages(['filters' => '"' . $text . '" is not an option of ' . $label . '. Options: ' . implode(', ', $field['options'] ?? []) . '.']);
            }

            return $match;
        }

        return $text;
    }

    private static function metric(?TransactionType $type, string $kind, mixed $raw): string
    {
        $raw = trim((string) ($raw ?? ''));

        if ($raw === '' || in_array(strtolower($raw), ['amount', 'money', 'sum'], true)) {
            return 'amount';
        }

        if (in_array(strtolower($raw), ['fields', 'all_fields', 'all'], true)) {
            if ($kind !== 'stat') {
                throw ValidationException::withMessages(['metric' => 'All fields works for stat cards only!']);
            }

            if (self::numericFields($type) === []) {
                throw ValidationException::withMessages(['metric' => 'All fields needs a type with number fields!']);
            }

            return 'fields';
        }

        if (!$type) {
            throw ValidationException::withMessages(['metric' => 'A field metric needs a transaction type!']);
        }

        $field = self::field($type, $raw);

        if (!$field || !in_array($field['type'] ?? '', self::NUMERIC, true)) {
            $keys = implode(', ', array_map(static fn (array $f): string => (string) $f['key'], self::numericFields($type)));

            throw ValidationException::withMessages(['metric' => 'Metric must be amount or a number field of ' . $type->name . ($keys !== '' ? ' (' . $keys . ')' : '') . '!']);
        }

        return (string) $field['key'];
    }

    private static function split(?TransactionType $type, string $kind, mixed $raw): ?string
    {
        $raw = trim((string) ($raw ?? ''));

        if ($kind === 'stat') {
            return null;
        }

        if ($raw === '' || strtolower($raw) === 'none') {
            if ($kind === 'pie') {
                throw ValidationException::withMessages(['split' => 'A pie card needs split: tag or a field!']);
            }

            return null;
        }

        if (in_array(strtolower($raw), ['tag', 'tags'], true)) {
            return 'tag';
        }

        $field = self::field($type, $raw);

        if (!$field || !in_array($field['type'] ?? '', self::SPLITTABLE, true)) {
            $keys = $type ? implode(', ', array_map(
                static fn (array $f): string => (string) $f['key'],
                array_filter($type->visibleFields(), static fn (array $f): bool => in_array($f['type'] ?? '', self::SPLITTABLE, true)),
            )) : '';

            throw ValidationException::withMessages(['split' => 'Split by tag' . ($keys !== '' ? ' or a field (' . $keys . ')' : ($type ? '' : ', or pick a type to split by its fields')) . '!']);
        }

        return (string) $field['key'];
    }
}
