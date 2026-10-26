<?php

namespace App\Actions\Agent\Documents;

use App\Models\User;
use App\Support\UserFormatter;

/**
 * Plan edits the user asked for in words ("skip lines 3-5", "Uber → Transport", "create the missing
 * tags", "dates are month first"). They come from the AI (DocumentAi::instructions) or from the
 * deterministic phrase parser below, are normalised here, and are applied by code to the rows:
 * the model never touches the rows themselves.
 *
 * Ops: skip_lines [{from, to, file}], skip_match [str], only {type, from, to, match, currency},
 * flip_sign {all, match}, set_tags [{match, tag, replace}], date_order, unknown_tags, tag_map
 * {lower unknown title: existing title | ''}, suggest_tags, unsupported [str].
 */
final class ImportInstructions
{
    private const MAX_ITEMS = 30;

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function normalize(array $json): array
    {
        $ops = [];
        $str = static fn (mixed $v, int $max = 80): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $strings = static fn (mixed $v): array => array_slice(array_values(array_filter(array_map(
            static fn ($s) => is_string($s) && trim($s) !== '' ? mb_substr(trim($s), 0, 80) : null,
            is_array($v) ? $v : (is_string($v) ? [$v] : []),
        ))), 0, self::MAX_ITEMS);
        $date = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        foreach (array_slice(is_array($json['skip_lines'] ?? null) ? $json['skip_lines'] : [], 0, self::MAX_ITEMS) as $range) {
            if (!is_array($range) || !is_numeric($range['from'] ?? null)) {
                continue;
            }
            $from = max(1, (int) $range['from']);
            $to = is_numeric($range['to'] ?? null) ? max($from, (int) $range['to']) : $from;
            $ops['skip_lines'][] = ['from' => $from, 'to' => min($to, $from + 100000), 'file' => is_int($range['file'] ?? null) ? $range['file'] : null];
        }
        if (($skip = $strings($json['skip_match'] ?? null)) !== []) {
            $ops['skip_match'] = $skip;
        }

        $only = is_array($json['only'] ?? null) ? $json['only'] : [];
        $onlyOps = array_filter([
            'type' => in_array($only['type'] ?? null, ['expense', 'income'], true) ? $only['type'] : null,
            'from' => $date($only['from'] ?? null),
            'to' => $date($only['to'] ?? null),
            'match' => $strings($only['match'] ?? null) ?: null,
            'currency' => ($c = $str($only['currency'] ?? null, 10)) !== null ? mb_strtoupper($c) : null,
        ], static fn ($v) => $v !== null);
        if ($onlyOps !== []) {
            $ops['only'] = $onlyOps;
        }

        $flip = is_array($json['flip_sign'] ?? null) ? $json['flip_sign'] : [];
        if (($flip['all'] ?? false) === true) {
            $ops['flip_sign'] = ['all' => true, 'match' => []];
        } elseif (($match = $strings($flip['match'] ?? null)) !== []) {
            $ops['flip_sign'] = ['all' => false, 'match' => $match];
        }

        foreach (array_slice(is_array($json['set_tags'] ?? null) ? $json['set_tags'] : [], 0, self::MAX_ITEMS) as $set) {
            $tag = is_array($set) ? $str($set['tag'] ?? null, 60) : null;
            if ($tag !== null) {
                $ops['set_tags'][] = ['match' => $strings($set['match'] ?? null), 'tag' => $tag, 'replace' => ($set['replace'] ?? false) === true];
            }
        }

        if (in_array($json['date_order'] ?? null, ['dmy', 'mdy'], true)) {
            $ops['date_order'] = $json['date_order'];
        }
        if (in_array($json['unknown_tags'] ?? null, ['create', 'none'], true)) {
            $ops['unknown_tags'] = $json['unknown_tags'];
        }
        foreach (array_slice(is_array($json['tag_map'] ?? null) ? $json['tag_map'] : [], 0, 100, true) as $from => $to) {
            if (is_string($from) && trim($from) !== '' && (is_string($to) || $to === null)) {
                $ops['tag_map'][mb_strtolower(trim($from))] = mb_substr(trim((string) $to), 0, 60);
            }
        }
        if (is_bool($json['suggest_tags'] ?? null)) {
            $ops['suggest_tags'] = $json['suggest_tags'];
        }
        if (($unsupported = $strings($json['unsupported'] ?? null)) !== []) {
            $ops['unsupported'] = array_slice($unsupported, 0, 5);
        }

        return $ops;
    }

    /**
     * Later edits win for single values; lists add up.
     *
     * @param array<string, mixed> $ops
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    public static function merge(array $ops, array $more): array
    {
        foreach ($more as $key => $value) {
            $ops[$key] = match ($key) {
                'skip_lines', 'skip_match', 'set_tags', 'unsupported' => array_slice(array_merge($ops[$key] ?? [], $value), 0, self::MAX_ITEMS * 2),
                'tag_map' => array_merge($ops[$key] ?? [], $value),
                'only' => array_merge($ops[$key] ?? [], $value),
                default => $value,
            };
        }

        return $ops;
    }

    /**
     * Common phrases understood without AI (English, Ukrainian, Russian). "complete" when nothing
     * meaningful is left over, so the AI isn't needed at all.
     *
     * @param list<string> $unknown unknown tag titles (as in the file)
     * @param array<string, string> $existing lower title => title
     * @return array{ops: array<string, mixed>, complete: bool}
     */
    public static function quick(string $text, array $unknown, array $existing, bool $reply): array
    {
        $ops = [];
        $rest = ' ' . mb_strtolower(trim($text)) . ' ';
        $take = static function (string $pattern) use (&$rest): bool {
            if (!preg_match($pattern, $rest)) {
                return false;
            }
            $rest = (string) preg_replace($pattern, ' ', $rest);

            return true;
        };

        // "Кафе → Їжа", "Taxi = Transport", "Gym -> none" (one per line or separated by , ;).
        $unknownByLower = [];
        foreach ($unknown as $title) {
            $unknownByLower[mb_strtolower(trim($title))] = $title;
        }
        $none = '/^(none|no|nothing|no tag|without( a)? tag|without|skip|-|—|без тегу|без тега|без тегів|без|нічого|ничего|пропусти)$/u';
        $create = '/^(create|new|create it|створи|створити|новий|новий тег|создай|создать|новый)$/u';
        foreach (preg_split('/\R|[;,]/u', (string) $text) ?: [] as $segment) {
            if (!preg_match('/^\s*(.+?)\s*(?:→|->|=>|=|—>|:)\s*(.+?)\s*\.?$/u', $segment, $m)) {
                continue;
            }
            $from = mb_strtolower(trim($m[1], " \t\"'«»“”"));
            $to = trim($m[2], " \t\"'«»“”");
            $toLower = mb_strtolower($to);
            if (!isset($unknownByLower[$from])) {
                continue;
            }
            if (preg_match($none, $toLower)) {
                $ops['tag_map'][$from] = '';
            } elseif (preg_match($create, $toLower)) {
                $ops['tag_map'][$from] = $unknownByLower[$from];
            } else {
                $ops['tag_map'][$from] = $existing[$toLower] ?? $to;
            }
            $rest = str_replace(mb_strtolower($segment), ' ', $rest);
        }

        $others = '(the rest|rest|others|other|all others|решта|решту|інші|інших|остальные|остальное|другие)';
        if ($take('/\b' . $others . '\b[^.;,\n]*?(without( a)? tags?|no tags?|без тег\w*|без)/u')
            || $take('/(without( a)? tags?|без тег\w*)[^.;,\n]*?\b' . $others . '\b/u')) {
            $ops['unknown_tags'] = 'none';
        } elseif ($take('/\b' . $others . '\b[^.;,\n]*?(create|створ\w*|созда\w*|нов\w*)/u')
            || $take('/(create|створ\w*|созда\w*)[^.;,\n]*?\b' . $others . '\b/u')) {
            $ops['unknown_tags'] = 'create';
        } elseif ($take('/(create|add|make)( the)?( missing| new| needed)? tags?( if needed| when needed| as needed)?|(створи|створити|створюй|додай|добав\w*)( відповідні| потрібні| нові| відсутні)? тег\w*( при потребі| якщо треба| за потреби)?|(створи|створюй|создай|создавай) нові( при потребі| якщо треба| за потреби)?|(создай|создать|добавь)( нужные| новые| недостающие)? тег\w*( при необходимости)?|\bcreate( them| it| all)?\b|^\s*(створи|створити|створюй|создай|создать)( їх| всі| все| их)?\s*$/u')) {
            $ops['unknown_tags'] = 'create';
        } elseif ($take('/(without|no|skip)( the)? tags?|(імпортуй |импортируй )?без тег\w*|^\s*(none|без)\s*$/u')) {
            $ops['unknown_tags'] = 'none';
            if (!$reply) {
                $ops['suggest_tags'] = false;
            }
        }

        if ($take('/(dates? (are |is )?month[- ]first|month before day|mm\/dd(\/yy(yy)?)?|american dates?|спочатку місяць|місяць спочатку|месяц первы\w*|сначала месяц)/u')) {
            $ops['date_order'] = 'mdy';
        } elseif ($take('/(dates? (are |is )?day[- ]first|day before month|dd\/mm(\/yy(yy)?)?|спочатку день|день спочатку|сначала день|день первы\w*)/u')) {
            $ops['date_order'] = 'dmy';
        }

        if ($take('/(only|just) (the )?expenses|(лише|тільки) витрати|только расходы/u')) {
            $ops['only']['type'] = 'expense';
        } elseif ($take('/(only|just) (the )?income|(лише|тільки) (доходи|надходження)|только доходы/u')) {
            $ops['only']['type'] = 'income';
        }

        if (preg_match('/\b(?:only|just|лише|тільки|только)\s+([A-Z]{3})\b/u', $text, $cur) && $take('/\b(only|just|лише|тільки|только)\s+' . mb_strtolower($cur[1]) . '\b( rows| рядки| строки)?/u')) {
            $ops['only']['currency'] = $cur[1];
        }

        if ($take('/(flip|invert|reverse|swap)( the)? signs?|(expenses|amounts) are positive|(інвертуй|зміни|поміняй|переверни) знак\w*|витрати (з плюсом|додатні)|(инвертируй|поменяй|смени) знак\w*/u')) {
            $ops['flip_sign'] = ['all' => true, 'match' => []];
        }

        if (!$reply && $take('/(don\'?t|do not) (suggest|guess|add) tags?|не (підбирай|пропонуй|додавай) тег\w*|не (подбирай|предлагай|добавляй) тег\w*/u')) {
            $ops['suggest_tags'] = false;
        }

        // Filler words left over don't need the AI.
        $left = (string) preg_replace('/\b(please|pls|and|the|a|them|it|all|just|then|import|this|these|file|files|ok|okay|yes|so|будь ласка|і|й|та|а|це|ці|їх|всі|все|імпортуй|пожалуйста|и|это|их|да|так|ок|файл|файли|файлы)\b/u', ' ', $rest);
        $words = preg_match_all('/[\p{L}\p{N}]{2,}/u', $left);

        return ['ops' => $ops, 'complete' => $ops !== [] && $words === 0];
    }

    /**
     * Apply the row edits (not the tag decisions: those are resolved in ImportPlan).
     *
     * @param list<array<string, mixed>> $rows draft rows (with 'f' = file index)
     * @param array<string, mixed> $ops
     * @param array<string, string> $existing lower title => title
     * @return array{rows: list<array<string, mixed>>, applied: list<string>}
     */
    public static function apply(User $user, array $rows, array $ops, array $existing, int $files): array
    {
        $applied = [];
        $matches = static function (array $row, array $patterns): bool {
            $note = mb_strtolower((string) ($row['note'] ?? ''));
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && str_contains($note, mb_strtolower($pattern))) {
                    return true;
                }
            }

            return false;
        };
        $quote = static fn (array $patterns): string => implode(' / ', array_map(static fn ($p) => '“' . $p . '”', array_slice($patterns, 0, 3)));

        if (isset($ops['date_order'])) {
            $swapped = 0;
            foreach ($rows as &$row) {
                $wants = $ops['date_order'];
                $read = $row['order'] ?? null;
                if (isset($row['swap']) && $read !== null && $read !== $wants) {
                    [$row['at'], $row['swap']] = [$row['swap'], $row['at']];
                    $row['order'] = $wants;
                    $swapped++;
                }
            }
            unset($row);
            $applied[] = 'Dates read ' . ($ops['date_order'] === 'mdy' ? 'month-first (MM/DD)' : 'day-first (DD/MM)')
                . ($swapped > 0 ? " ({$swapped} changed)" : '');
        }

        foreach ($ops['skip_lines'] ?? [] as $range) {
            $before = count($rows);
            $rows = array_values(array_filter($rows, static fn (array $row) => !(
                ($range['file'] === null || $range['file'] === $row['f'])
                && $row['line'] >= $range['from'] && $row['line'] <= $range['to']
            )));
            $label = $range['from'] === $range['to'] ? "line {$range['from']}" : "lines {$range['from']}–{$range['to']}";
            if ($range['file'] !== null && $files > 1) {
                $label .= ' of file ' . ($range['file'] + 1);
            }
            $applied[] = "Skipped {$label} (" . ($before - count($rows)) . ')';
        }

        if (($patterns = $ops['skip_match'] ?? []) !== []) {
            $before = count($rows);
            $rows = array_values(array_filter($rows, static fn (array $row) => !$matches($row, $patterns)));
            $applied[] = 'Skipped rows with ' . $quote($patterns) . ' (' . ($before - count($rows)) . ')';
        }

        if (isset($ops['flip_sign'])) {
            $count = 0;
            foreach ($rows as &$row) {
                if ($ops['flip_sign']['all'] || $matches($row, $ops['flip_sign']['match'])) {
                    $row['amount'] = -$row['amount'];
                    $count++;
                }
            }
            unset($row);
            $applied[] = $ops['flip_sign']['all'] ? 'Signs flipped on every row' : 'Signs flipped on rows with ' . $quote($ops['flip_sign']['match']) . " ({$count})";
        }

        if (($only = $ops['only'] ?? []) !== []) {
            $before = count($rows);
            $rows = array_values(array_filter($rows, static function (array $row) use ($only, $matches): bool {
                return !(
                    (($only['type'] ?? null) === 'expense' && $row['amount'] > 0)
                    || (($only['type'] ?? null) === 'income' && $row['amount'] < 0)
                    || (isset($only['from']) && $row['at'] < $only['from'])
                    || (isset($only['to']) && $row['at'] > $only['to'])
                    || (isset($only['match']) && !$matches($row, $only['match']))
                    || (isset($only['currency']) && ($row['cur'] ?? null) !== null && mb_strtoupper((string) $row['cur']) !== $only['currency'])
                );
            }));
            $parts = [];
            if (isset($only['type'])) {
                $parts[] = $only['type'] === 'expense' ? 'expenses' : 'income';
            }
            if (isset($only['from']) || isset($only['to'])) {
                $parts[] = (isset($only['from']) ? UserFormatter::formatDate($user, $only['from']) : '…') . ' – ' . (isset($only['to']) ? UserFormatter::formatDate($user, $only['to']) : '…');
            }
            if (isset($only['match'])) {
                $parts[] = 'rows with ' . $quote($only['match']);
            }
            if (isset($only['currency'])) {
                $parts[] = $only['currency'] . ' rows';
            }
            $applied[] = 'Only ' . implode(', ', $parts) . ' (' . ($before - count($rows)) . ' left out)';
        }

        foreach ($ops['set_tags'] ?? [] as $set) {
            $title = $existing[mb_strtolower($set['tag'])] ?? $set['tag'];
            $count = 0;
            foreach ($rows as &$row) {
                if ($set['match'] === [] || $matches($row, $set['match'])) {
                    if ($set['replace']) {
                        $row['tags'] = [];
                        $row['sug'] = [];
                    }
                    $row['add'] = array_values(array_unique(array_merge($row['add'] ?? [], [$title])));
                    $count++;
                }
            }
            unset($row);
            $new = isset($existing[mb_strtolower($set['tag'])]) ? '' : ' (new tag)';
            $applied[] = "Tag “{$title}”{$new} on " . ($set['match'] === [] ? 'every row' : 'rows with ' . $quote($set['match'])) . " ({$count})";
        }

        foreach ($ops['unsupported'] ?? [] as $what) {
            $applied[] = "Not done (not supported here): {$what}";
        }

        return ['rows' => $rows, 'applied' => $applied];
    }
}
