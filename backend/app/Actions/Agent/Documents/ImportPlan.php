<?php

namespace App\Actions\Agent\Documents;

use App\Actions\Tags\ListTagsAction;
use App\Models\Tag;
use App\Models\User;
use App\Support\UserFormatter;
use Carbon\Carbon;

/**
 * The single Confirm card of a bulk import, built from an import draft (DocumentImport): the
 * user's edits applied, tags resolved (existing / to create / dropped / still to ask about),
 * rows already saved (same date, amount and note) skipped, tags suggested from history for
 * untagged rows, and a preview with count, totals, date range, the first rows and warnings.
 */
final class ImportPlan
{
    private const PREVIEW_ROWS = 10;

    private const LIST_LINES = 8;

    /** Past transactions looked at for tag suggestions. */
    private const HISTORY_LIMIT = 5000;

    public function __construct(
        private readonly ListTagsAction $listTags,
    ) {}

    /**
     * @param array<string, mixed> $draft
     * @return array{payload: array<string, mixed>, preview: string, count: int, summary: string}
     *     |array{ask: string, unknown: list<string>, head: string}
     *     |array{error: string}
     */
    public function build(User $user, array $draft): array
    {
        $tagsByTitle = [];
        $titles = [];
        $labels = [];
        /** @var Tag $tag */
        foreach ($this->listTags->execute($user) as $tag) {
            $lower = mb_strtolower(trim((string) $tag->title));
            $tagsByTitle[$lower] ??= (int) $tag->id;
            $titles[$lower] ??= trim((string) $tag->title);
            $labels[(int) $tag->id] = trim(($tag->emoji ? $tag->emoji . ' ' : '') . $tag->title);
        }

        $source = (string) ($draft['source'] ?? 'the file');
        $ops = is_array($draft['ops'] ?? null) ? $draft['ops'] : [];
        $edited = ImportInstructions::apply($user, $draft['rows'] ?? [], $ops, $titles, count($draft['files'] ?? []));
        $rows = $edited['rows'];
        if ($rows === []) {
            return ['error' => ($draft['rows'] ?? []) !== []
                ? "Nothing left to import from {$source} after your instructions."
                : "No transactions found in {$source}."];
        }

        $existing = $this->existingKeys($user, $rows);
        $duplicates = 0;
        $fresh = [];
        foreach ($rows as $row) {
            if (isset($existing[self::key($row['at'], $row['amount'], $row['note'] ?? null)])) {
                $duplicates++;

                continue;
            }
            $fresh[] = $row;
        }
        if ($fresh === []) {
            return ['error' => "Every transaction in {$source} is already saved (same date, amount and note), so there is nothing to import."];
        }

        // Tags: existing → id; unknown → the user's map, else the policy (create / none), else ask.
        $policy = $ops['unknown_tags'] ?? null;
        $map = is_array($ops['tag_map'] ?? null) ? $ops['tag_map'] : [];
        $unresolved = [];
        $unresolvedRows = 0;
        $dropped = [];
        $create = [];
        $items = [];
        foreach ($fresh as $row) {
            $ids = [];
            $new = [];
            $missing = false;
            foreach ($row['tags'] ?? [] as $title) {
                $lower = mb_strtolower(trim((string) $title));
                if ($lower === '') {
                    continue;
                }
                if (isset($tagsByTitle[$lower])) {
                    $ids[$tagsByTitle[$lower]] = $tagsByTitle[$lower];
                } elseif (array_key_exists($lower, $map)) {
                    $target = mb_strtolower(trim((string) $map[$lower]));
                    if ($target === '') {
                        $dropped[$lower] = $title;
                    } elseif (isset($tagsByTitle[$target])) {
                        $ids[$tagsByTitle[$target]] = $tagsByTitle[$target];
                    } else {
                        $new[$target] = trim((string) $map[$lower]);
                    }
                } elseif ($policy === 'create') {
                    $new[$lower] = trim((string) $title);
                } elseif ($policy === 'none' || ($row['soft'] ?? false)) {
                    // AI-suggested names that don't exist are only created when asked to.
                    if (!($row['soft'] ?? false)) {
                        $dropped[$lower] = $title;
                    }
                } else {
                    $unresolved[$lower] = trim((string) $title);
                    $missing = true;
                }
            }
            foreach ($row['add'] ?? [] as $title) {
                $lower = mb_strtolower(trim((string) $title));
                if (isset($tagsByTitle[$lower])) {
                    $ids[$tagsByTitle[$lower]] = $tagsByTitle[$lower];
                } elseif ($lower !== '') {
                    $new[$lower] = trim((string) $title);
                }
            }
            $unresolvedRows += $missing ? 1 : 0;
            foreach ($new as $lower => $title) {
                $create[$lower] ??= mb_substr($title, 0, 60);
            }
            $items[] = [
                'amount' => round((float) $row['amount'], 2),
                'at' => $row['at'],
                'note' => $row['note'] ?? null,
                'tag_ids' => array_values($ids),
                'new_tags' => array_values(array_map(static fn (string $lower) => $create[$lower], array_keys($new))),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $count = count($items);
        $from = $items[0]['at'];
        $to = $items[$count - 1]['at'];
        $range = UserFormatter::formatDate($user, $from) . ($from !== $to ? ' – ' . UserFormatter::formatDate($user, $to) : '');
        $head = "📄 Import from {$source}\n" . $count . ' transaction' . ($count === 1 ? '' : 's') . " · {$range}";

        if ($unresolved !== []) {
            $names = array_values($unresolved);
            $shown = implode(', ', array_slice($names, 0, 12)) . (count($names) > 12 ? ' and ' . (count($names) - 12) . ' more' : '');

            return [
                'ask' => $head . "\n\n🏷 These tags don't exist yet: {$shown} ({$unresolvedRows} row" . ($unresolvedRows === 1 ? '' : 's') . ").\n"
                    . 'Create them, map them to your tags (e.g. “' . $names[0] . ' → ' . ($titles !== [] ? reset($titles) : 'Food') . '”), or import without them?'
                    . "\nYou can also write other changes, e.g. “skip lines 3-5” or “only expenses”.",
                'unknown' => $names,
                'head' => $head,
            ];
        }

        $suggested = 0;
        if (($ops['suggest_tags'] ?? true) !== false) {
            $suggested = $this->suggestFromHistory($user, $items);
        }

        $income = 0.0;
        $expense = 0.0;
        foreach ($items as $item) {
            $item['amount'] > 0 ? $income += $item['amount'] : $expense += $item['amount'];
        }

        $preview = [$head];
        $totals = [];
        if ($income > 0) {
            $totals[] = 'Income: ' . UserFormatter::formatMoney($user, $income);
        }
        if ($expense < 0) {
            $totals[] = 'Expenses: ' . UserFormatter::formatMoney($user, $expense);
        }
        $preview[] = implode(' · ', $totals);
        $preview[] = '';

        foreach (array_slice($items, 0, self::PREVIEW_ROWS) as $item) {
            $tags = array_values(array_filter(array_map(static fn (int $id) => $labels[$id] ?? null, $item['tag_ids'])));
            foreach ($item['new_tags'] as $title) {
                $tags[] = '🆕 ' . $title;
            }
            $tagText = $tags !== [] ? ' (' . (($item['suggested'] ?? false) ? '~' : '') . implode(', ', $tags) . ')' : '';
            $preview[] = '• ' . UserFormatter::formatDate($user, $item['at']) . '  ' . UserFormatter::formatMoney($user, $item['amount'])
                . ($item['note'] ? '  ' . mb_strimwidth($item['note'], 0, 48, '…') : '') . $tagText;
        }
        if ($count > self::PREVIEW_ROWS) {
            $preview[] = '…and ' . ($count - self::PREVIEW_ROWS) . ' more';
        }

        if ($edited['applied'] !== []) {
            $preview[] = '';
            $preview[] = '📝 From your message:';
            foreach ($edited['applied'] as $line) {
                $preview[] = '– ' . $line;
            }
        }
        $mapped = array_filter($map, static fn ($to) => $to !== '');
        if ($mapped !== []) {
            $preview[] = '– Tags mapped: ' . implode(', ', array_slice(array_map(
                static fn (string $from, string $to) => "{$from} → {$to}",
                array_keys($mapped),
                $mapped,
            ), 0, self::LIST_LINES));
        }

        $info = [];
        if ($create !== []) {
            $info[] = '🆕 Will create tags: ' . implode(', ', array_slice(array_values($create), 0, 20)) . (count($create) > 20 ? '…' : '');
        }
        if ($suggested > 0) {
            $info[] = "~ Tags suggested for {$suggested} untagged row" . ($suggested === 1 ? '' : 's') . ' from your past transactions with the same note.';
        }
        $info = array_merge($info, $this->warnings($user, $draft, $rows, $duplicates, $dropped, $ops));
        if ($info !== []) {
            $preview[] = '';
            array_push($preview, ...$info);
        }

        $untagged = count(array_filter($items, static fn (array $item) => $item['tag_ids'] === [] && $item['new_tags'] === []));
        $payloadItems = array_map(static fn (array $item) => [
            'amount' => $item['amount'],
            'at' => $item['at'],
            'note' => $item['note'],
            'tag_ids' => $item['tag_ids'],
        ] + ($item['new_tags'] !== [] ? ['new_tags' => $item['new_tags']] : []), $items);

        return [
            'payload' => array_filter([
                'source' => mb_substr($source, 0, 120),
                'transactions' => $payloadItems,
                'create_tags' => array_values($create),
            ], static fn ($v) => $v !== []),
            'preview' => implode("\n", $preview),
            'count' => $count,
            'summary' => "{$count} transactions from {$source} ({$range}"
                . ($untagged > 0 ? ", {$untagged} untagged" : '')
                . ($create !== [] ? ', ' . count($create) . ' new tags' : '') . ')',
        ];
    }

    /**
     * Same date + amount + note, as compared everywhere in the import.
     */
    public static function key(string $at, float $amount, ?string $note): string
    {
        return $at . '|' . number_format($amount, 2, '.', '') . '|' . mb_strtolower(trim((string) $note));
    }

    /**
     * Untagged rows get the most common tag set of past transactions with the same note.
     *
     * @param list<array<string, mixed>> $items
     */
    private function suggestFromHistory(User $user, array &$items): int
    {
        $notes = [];
        foreach ($items as $item) {
            if ($item['tag_ids'] === [] && $item['new_tags'] === [] && is_string($item['note']) && trim($item['note']) !== '') {
                $notes[self::normalizeNote($item['note'])] = true;
            }
        }
        if ($notes === []) {
            return 0;
        }

        $sets = [];
        foreach (array_chunk(array_keys($notes), 300) as $chunk) {
            $user->transactions()
                ->whereNotNull('note')
                ->whereRaw('lower(trim(note)) in (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_map(static fn ($n) => (string) $n, $chunk))
                ->whereHas('tags', static fn ($q) => $q->whereNull('tags.deleted_at'))
                ->with(['tags' => static fn ($q) => $q->whereNull('tags.deleted_at')->select('tags.id')])
                ->latest('at')
                ->limit(self::HISTORY_LIMIT)
                ->get(['id', 'note', 'at'])
                ->each(static function ($tx) use (&$sets): void {
                    $ids = $tx->tags->pluck('id')->map(static fn ($id) => (int) $id)->sort()->values()->all();
                    if ($ids === []) {
                        return;
                    }
                    $note = self::normalizeNote((string) $tx->note);
                    $setKey = implode(',', $ids);
                    $sets[$note][$setKey] = ($sets[$note][$setKey] ?? 0) + 1;
                });
        }

        $count = 0;
        foreach ($items as &$item) {
            if ($item['tag_ids'] !== [] || $item['new_tags'] !== [] || !is_string($item['note'])) {
                continue;
            }
            $options = $sets[self::normalizeNote($item['note'])] ?? null;
            if ($options === null) {
                continue;
            }
            arsort($options);
            $item['tag_ids'] = array_map('intval', explode(',', (string) array_key_first($options)));
            $item['suggested'] = true;
            $count++;
        }
        unset($item);

        return $count;
    }

    private static function normalizeNote(string $note): string
    {
        return mb_strtolower(trim($note));
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<array<string, mixed>> $rows rows after the user's edits
     * @param array<string, string> $dropped unknown tags imported without
     * @param array<string, mixed> $ops
     * @return list<string>
     */
    private function warnings(User $user, array $draft, array $rows, int $duplicates, array $dropped, array $ops): array
    {
        $warnings = [];
        $files = $draft['files'] ?? [];
        $multi = count($files) > 1;

        foreach ($draft['notes'] ?? [] as $note) {
            $warnings[] = (string) $note;
        }
        if ($draft['capped'] ?? false) {
            $warnings[] = '⚠️ Only the first ' . DocumentFile::maxRows() . ' rows are imported. Split the file to import the rest.';
        }
        $skipped = $draft['skipped'] ?? [];
        if ($skipped !== []) {
            $byReason = [];
            foreach ($skipped as $row) {
                $where = $multi ? (($files[$row['f']]['name'] ?? 'file') . ' ') : '';
                $byReason[$row['reason']][] = $where . $row['line'];
            }
            $parts = [];
            foreach ($byReason as $reason => $lineNumbers) {
                $shown = array_slice($lineNumbers, 0, self::LIST_LINES);
                $parts[] = $reason . ': line' . (count($lineNumbers) > 1 ? 's ' : ' ') . implode(', ', $shown)
                    . (count($lineNumbers) > count($shown) ? '…' : '');
            }
            $n = count($skipped);
            $warnings[] = "⚠️ {$n} row" . ($n === 1 ? '' : 's') . ' skipped (' . implode('; ', $parts) . ')';
        }
        if (($draft['overlap'] ?? 0) > 0) {
            $n = (int) $draft['overlap'];
            $warnings[] = "ℹ️ {$n} row" . ($n === 1 ? '' : 's') . ' appeared in more than one ' . (($draft['screenshots'] ?? false) ? 'screenshot' : 'file') . ' and ' . ($n === 1 ? 'is' : 'are') . ' imported once';
        }
        if ($duplicates > 0) {
            $warnings[] = "⚠️ {$duplicates} possible duplicate" . ($duplicates === 1 ? '' : 's')
                . ' skipped: already saved with the same date, amount and note';
        }
        if ($dropped !== []) {
            $names = array_values($dropped);
            $warnings[] = '⚠️ Saved without these tags (they don\'t exist): '
                . implode(', ', array_slice($names, 0, self::LIST_LINES)) . (count($names) > self::LIST_LINES ? '…' : '');
        }
        if (!isset($ops['date_order'])) {
            foreach ($files as $file) {
                if (($file['ambiguous'] ?? false) && ($file['samples'] ?? []) !== []) {
                    $sample = $file['samples'][0];
                    $read = ($file['order'] ?? 'dmy') === 'mdy' ? 'month-first (MM/DD)' : 'day-first (DD/MM)';
                    $warnings[] = "⚠️ Dates in {$file['name']} like {$sample} can be read both ways: read as {$read}"
                        . (($file['kind'] ?? '') === DocumentFile::QIF ? ' (the QIF default)' : ' (your date format)')
                        . '. If that is wrong, cancel and send it again with “dates are ' . (($file['order'] ?? 'dmy') === 'mdy' ? 'day' : 'month') . ' first”.';
                }
            }
        }

        $currencies = array_values(array_unique(array_filter(array_map(static fn (array $row) => $row['cur'] ?? null, $rows))));
        if (count($currencies) > 1) {
            $warnings[] = '⚠️ Several currencies (' . implode(', ', array_slice($currencies, 0, 5)) . '): amounts are saved as they are, without conversion. Write e.g. “only USD” to keep one.';
        }

        $amounts = array_column($rows, 'amount');
        if (count($amounts) >= 3 && min($amounts) > 0) {
            $warnings[] = '⚠️ Every amount is positive, so all rows are saved as income. If they are expenses, cancel and send the file again with “flip the signs”.';
        }

        return $warnings;
    }

    /**
     * Keys of transactions already saved in the file's date range.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, true>
     */
    private function existingKeys(User $user, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $dates = array_column($rows, 'at');
        $keys = [];

        $user->transactions()
            ->whereBetween('at', [Carbon::parse(min($dates))->startOfDay(), Carbon::parse(max($dates))->endOfDay()])
            ->select(['id', 'at', 'amount', 'note'])
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$keys): void {
                foreach ($chunk as $tx) {
                    $keys[self::key($tx->at->toDateString(), (float) $tx->amount, $tx->note)] = true;
                }
            });

        return $keys;
    }
}
