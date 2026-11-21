<?php

namespace App\Actions\Dashboard;

use App\Actions\TransactionTypes\AnalyzeTransactionTypeAction;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\DashboardCardConfig;
use App\Support\PeriodRange;
use App\Support\TagTree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Numbers for one "My cards" card over the Analytics date range: a stat (with the previous
 * period, cut at the same elapsed day like the rest of Analytics), a chart (metric per day /
 * week / month, optionally one series per tag or field value, at most 6) or a pie (share by
 * tag or field value, at most 6 slices). Used by the web API (cards + live preview) and by
 * list_dashboard_cards for the assistant and MCP.
 */
class DashboardCardData
{
    public function __construct(private readonly AnalyzeTransactionTypeAction $analyze) {}

    /**
     * @param array<string, mixed> $config normalized (DashboardCardConfig::normalize)
     * @return array<string, mixed>
     */
    public function build(User $user, string $kind, array $config, PeriodRange $range): array
    {
        $type = $config['type_id'] ? $user->transactionTypes()->withTrashed()->find($config['type_id']) : null;
        $tree = TagTree::for($user);
        $current = $this->transactions($user, $config, $type, $tree, $range->from, $range->to);
        $metric = $this->metricMeta($type, $config);

        $base = [
            'label' => DashboardCardConfig::label($user, $kind, $config, $type),
            'type' => $type ? ['id' => (int) $type->id, 'name' => $type->name, 'emoji' => $type->emoji, 'color' => $type->color] : null,
            'period' => ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString()],
            'count' => $current->count(),
            'agg' => $config['agg'],
            'flow' => $config['flow'],
            ...$metric,
        ];

        return $base + match ($kind) {
            'stat' => $this->stat($user, $config, $type, $tree, $range, $current),
            'chart' => $this->chart($config, $type, $tree, $range, $current),
            default => $this->pie($config, $type, $tree, $current),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return Collection<int, Transaction>
     */
    private function transactions(User $user, array $config, ?TransactionType $type, TagTree $tree, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->onDays($from->toDateString(), $to->toDateString())
            ->with('tags:id');

        if ($type) {
            $query->where('transaction_type_id', $type->id);

            if (($config['filters'] ?? []) !== []) {
                $this->analyze->applyFilters($query, $type, $config['filters']);
            }
        }

        match ($config['flow'] ?? 'all') {
            'expense' => $query->where('amount', '<', 0),
            'income' => $query->where('amount', '>', 0),
            default => null,
        };

        if (($config['tag_ids'] ?? []) !== []) {
            $ids = $tree->expand($config['tag_ids']);
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $ids));
        }

        return $query->orderBy('at')->get();
    }

    /**
     * @param array<string, mixed> $config
     * @return array{metric: string, metric_label: string, unit: ?string, money: bool}
     */
    private function metricMeta(?TransactionType $type, array $config): array
    {
        if ($config['metric'] === 'amount' || $config['metric'] === 'fields') {
            return [
                'metric' => $config['metric'],
                'metric_label' => $config['metric'] === 'amount' ? 'Amount' : 'Fields',
                'unit' => null,
                'money' => $config['metric'] === 'amount' && $config['agg'] !== 'count',
            ];
        }

        $field = DashboardCardConfig::field($type, $config['metric']) ?? [];
        $unit = $field['unit'] ?? null;

        return [
            'metric' => $config['metric'],
            'metric_label' => (string) ($field['label'] ?? $config['metric']),
            'unit' => $config['agg'] === 'count' ? null : $unit,
            'money' => $config['agg'] !== 'count' && in_array($unit, ['₴', 'UAH', 'грн'], true),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Collection<int, Transaction> $current
     * @return array<string, mixed>
     */
    private function stat(User $user, array $config, ?TransactionType $type, TagTree $tree, PeriodRange $range, Collection $current): array
    {
        $previousTo = $range->isPartial() || $range->to->gte($range->today) ? $range->previousToDate() : $range->previousTo;
        $previous = $this->transactions($user, $config, $type, $tree, $range->previousFrom, $previousTo);
        $out = [
            'previous_period' => ['from' => $range->previousFrom->toDateString(), 'to' => $previousTo->toDateString()],
            'previous_count' => $previous->count(),
        ];

        if ($config['metric'] === 'fields') {
            $out['fields'] = array_map(function (array $field) use ($config, $type, $current, $previous): array {
                $key = (string) $field['key'];
                $agg = $config['agg'] === 'auto' ? DashboardCardConfig::defaultFieldAgg($type, $key) : $config['agg'];
                $value = $this->aggregate($this->values($current, $key, $config), $agg);
                $was = $this->aggregate($this->values($previous, $key, $config), $agg);

                return [
                    'key' => $key,
                    'label' => (string) ($field['label'] ?? $key),
                    'agg' => $agg,
                    'unit' => $agg === 'count' ? null : ($field['unit'] ?? null),
                    'value' => $value,
                    'previous' => $was,
                    'change_pct' => $this->change($value, $was),
                ];
            }, DashboardCardConfig::numericFields($type));

            return $out;
        }

        $value = $this->aggregate($this->values($current, $config['metric'], $config), $config['agg']);
        $was = $this->aggregate($this->values($previous, $config['metric'], $config), $config['agg']);

        return $out + ['value' => $value, 'previous' => $was, 'change_pct' => $this->change($value, $was)];
    }

    /**
     * @param array<string, mixed> $config
     * @param Collection<int, Transaction> $current
     * @return array<string, mixed>
     */
    private function chart(array $config, ?TransactionType $type, TagTree $tree, PeriodRange $range, Collection $current): array
    {
        $days = $range->days();
        $bucket = $days <= 31 ? 'day' : ($days <= 92 ? 'week' : 'month');
        $keys = [];
        $cursor = $this->bucketStart($range->from, $bucket);

        while ($cursor->lte($range->to)) {
            $keys[] = $this->bucketKey($cursor, $bucket);
            $cursor = match ($bucket) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        $groups = $config['split'] === null
            ? [['label' => $config['metric'] === 'amount' ? 'Amount' : 'Value', 'value' => null, 'items' => $current->all()]]
            : $this->groups($current, $config, $type, $tree);

        $series = array_map(function (array $group) use ($config, $keys, $bucket): array {
            $byBucket = [];

            foreach ($group['items'] as $tx) {
                $byBucket[$this->bucketKey(CarbonImmutable::parse($tx->at->toDateString()), $bucket)][] = $tx;
            }

            return [
                'label' => $group['label'],
                'value' => $group['value'],
                'tag_id' => $group['tag_id'] ?? null,
                'total' => $this->aggregate($this->values(collect($group['items']), $config['metric'], $config), $config['agg']),
                'points' => array_map(
                    fn (string $key): ?float => isset($byBucket[$key])
                        ? $this->aggregate($this->values(collect($byBucket[$key]), $config['metric'], $config), $config['agg'])
                        : (in_array($config['agg'], ['sum', 'count'], true) ? 0.0 : null),
                    $keys,
                ),
            ];
        }, $groups);

        return ['chart' => $config['chart'], 'split' => $config['split'], 'bucket' => $bucket, 'buckets' => $keys, 'series' => $series];
    }

    /**
     * @param array<string, mixed> $config
     * @param Collection<int, Transaction> $current
     * @return array<string, mixed>
     */
    private function pie(array $config, ?TransactionType $type, TagTree $tree, Collection $current): array
    {
        $slices = array_map(fn (array $group): array => [
            'label' => $group['label'],
            'value' => $group['value'],
            'tag_id' => $group['tag_id'] ?? null,
            'count' => count($group['items']),
            'total' => $this->aggregate($this->values(collect($group['items']), $config['metric'], $config), $config['agg']) ?? 0.0,
        ], $this->groups($current, $config, $type, $tree));

        $slices = array_values(array_filter($slices, static fn (array $s): bool => abs($s['total']) > 0.000001));
        $sum = array_sum(array_map(static fn (array $s): float => abs($s['total']), $slices));

        foreach ($slices as &$slice) {
            $slice['share'] = $sum > 0 ? round(abs($slice['total']) / $sum * 100, 1) : 0.0;
        }
        unset($slice);

        return ['split' => $config['split'], 'slices' => $slices, 'total' => round($sum, 2)];
    }

    /**
     * Transactions grouped by top-level tag or field value; top 5 + Other when there are more than 6.
     *
     * @param Collection<int, Transaction> $txs
     * @param array<string, mixed> $config
     * @return list<array{label: string, value: mixed, tag_id?: ?int, items: list<Transaction>}>
     */
    private function groups(Collection $txs, array $config, ?TransactionType $type, TagTree $tree): array
    {
        $groups = [];

        if ($config['split'] === 'tag') {
            foreach ($txs as $tx) {
                $roots = $tree->rootsOf($tx->tags->pluck('id'));

                if ($roots === []) {
                    $groups['untagged'] ??= ['label' => 'Untagged', 'value' => null, 'tag_id' => null, 'items' => []];
                    $groups['untagged']['items'][] = $tx;
                }

                foreach ($roots as $root) {
                    $groups['t' . $root] ??= ['label' => $tree->label($root) ?: '#' . $root, 'value' => $tree->meta($root)['title'], 'tag_id' => $root, 'items' => []];
                    $groups['t' . $root]['items'][] = $tx;
                }
            }
        } else {
            $field = DashboardCardConfig::field($type, (string) $config['split']) ?? ['type' => 'text'];

            foreach ($txs as $tx) {
                $raw = is_array($tx->data) ? ($tx->data[$config['split']] ?? null) : null;

                if (($field['type'] ?? '') === 'boolean') {
                    $raw = $raw === null || $raw === '' ? null : (in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes'], true) ? 'Yes' : 'No');
                }

                $text = is_scalar($raw) ? trim((string) $raw) : '';
                $key = $text === '' ? "\0" : mb_strtolower($text);
                $groups[$key] ??= ['label' => $text === '' ? 'Not set' : $text, 'value' => $text === '' ? null : $text, 'items' => []];
                $groups[$key]['items'][] = $tx;
            }
        }

        $groups = array_values($groups);
        $weight = fn (array $g): float => abs((float) ($this->aggregate($this->values(collect($g['items']), $config['metric'], $config), $config['agg'] === 'count' ? 'count' : 'sum') ?? 0));
        usort($groups, function (array $a, array $b) use ($weight): int {
            $aEmpty = $a['value'] === null;
            $bEmpty = $b['value'] === null;

            if ($aEmpty !== $bEmpty) {
                return $aEmpty ? 1 : -1;
            }

            return $weight($b) <=> $weight($a) ?: count($b['items']) <=> count($a['items']);
        });

        if (count($groups) <= DashboardCardConfig::MAX_SERIES) {
            return $groups;
        }

        $head = array_slice($groups, 0, DashboardCardConfig::MAX_SERIES - 1);
        $rest = array_merge(...array_map(static fn (array $g): array => $g['items'], array_slice($groups, DashboardCardConfig::MAX_SERIES - 1)));
        $head[] = ['label' => 'Other', 'value' => null, 'tag_id' => null, 'items' => $rest];

        return $head;
    }

    /**
     * Metric values of the transactions (count uses one per transaction that has the value).
     *
     * @param Collection<int, Transaction> $txs
     * @param array<string, mixed> $config
     * @return list<float>
     */
    private function values(Collection $txs, string $metric, array $config): array
    {
        $out = [];

        foreach ($txs as $tx) {
            if ($metric === 'amount' || $metric === 'fields') {
                $amount = (float) $tx->amount;
                $out[] = ($config['flow'] ?? 'all') === 'expense' ? abs($amount) : $amount;

                continue;
            }

            $raw = is_array($tx->data) ? ($tx->data[$metric] ?? null) : null;

            if (is_string($raw)) {
                $raw = str_replace(',', '.', trim($raw));
            }

            if (is_numeric($raw)) {
                $out[] = (float) $raw;
            }
        }

        return $out;
    }

    /**
     * @param list<float> $values
     */
    private function aggregate(array $values, string $agg): ?float
    {
        if ($agg === 'count') {
            return (float) count($values);
        }

        if ($values === []) {
            return $agg === 'sum' ? 0.0 : null;
        }

        $value = match ($agg) {
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => array_sum($values),
        };

        return round($value, 2);
    }

    private function change(?float $now, ?float $was): ?float
    {
        if ($now === null || $was === null || abs($was) < 0.000001) {
            return null;
        }

        return round(($now - $was) / abs($was) * 100, 1);
    }

    private function bucketStart(CarbonImmutable $day, string $bucket): CarbonImmutable
    {
        return match ($bucket) {
            'day' => $day->startOfDay(),
            'week' => $day->startOfWeek(CarbonImmutable::MONDAY),
            default => $day->startOfMonth(),
        };
    }

    private function bucketKey(CarbonImmutable $day, string $bucket): string
    {
        return match ($bucket) {
            'day' => $day->toDateString(),
            'week' => $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            default => $day->format('Y-m'),
        };
    }
}
