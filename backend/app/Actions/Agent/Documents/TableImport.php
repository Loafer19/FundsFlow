<?php

namespace App\Actions\Agent\Documents;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

/**
 * Turns spreadsheet rows into transactions: finds the header row and its columns (English,
 * Ukrainian and Russian names, common bank exports), then parses dates (ISO, d.m.Y, d/m/Y or
 * m/d/Y, Excel cells) and amounts (decimal comma or dot, spaces, "(12.50)", unicode minus,
 * separate debit/credit columns, a type column). Pure: no AI and no database here.
 */
final class TableImport
{
    public const FIELDS = ['date', 'amount', 'debit', 'credit', 'note', 'tag', 'currency', 'type'];

    /** Rows above the header (bank name, account, period) that are scanned for it. */
    private const HEADER_SCAN = 30;

    private const NOTE_MAX = 255;

    /**
     * Column guess from header names, or null when date + amount (or debit/credit) aren't found.
     *
     * @param list<list<mixed>> $rows
     * @return array<string, int|string|null>|null mapping: header (row index), FIELDS => column index
     */
    public function guessMapping(array $rows): ?array
    {
        foreach (array_slice($rows, 0, self::HEADER_SCAN, true) as $index => $row) {
            $mapping = $this->mapHeader($row);
            if ($mapping !== null) {
                return ['header' => $index] + $mapping;
            }
        }

        return null;
    }

    /**
     * Validate a mapping proposed by the AI against the actual table.
     *
     * @param array<string, mixed> $proposed
     * @param list<list<mixed>> $rows
     * @return array<string, int|string|null>|null
     */
    public function acceptMapping(array $proposed, array $rows, int $sampleRows): ?array
    {
        $width = max(array_map('count', array_slice($rows, 0, $sampleRows + 1)) ?: [0]);
        $mapping = [];
        foreach (self::FIELDS as $field) {
            $value = $proposed[$field] ?? null;
            $mapping[$field] = is_int($value) && $value >= 0 && $value < $width ? $value : null;
        }
        if ($mapping['date'] === null || ($mapping['amount'] === null && $mapping['debit'] === null && $mapping['credit'] === null)) {
            return null;
        }

        $header = $proposed['header_row'] ?? null;
        $mapping['header'] = is_int($header) && $header >= 0 && $header < $sampleRows ? $header : null;
        $order = $proposed['date_order'] ?? null;
        $mapping['date_order'] = in_array($order, ['dmy', 'mdy', 'ymd'], true) ? $order : null;

        return $mapping;
    }

    /**
     * @param list<list<mixed>> $rows
     * @param array<string, int|string|null> $mapping
     * @return array{
     *     rows: list<array{line: int, at: string, amount: float, note: ?string, tag_titles: list<string>, currency: ?string}>,
     *     skipped: list<array{line: int, reason: string}>,
     *     capped: bool,
     *     ambiguous: bool,
     *     order: string,
     *     samples: list<string>
     * }
     *
     * ambiguous: every "a/b/yyyy" date fits both day-first and month-first and nothing said which;
     * the rows then carry 'swap' (the other reading) so a later caption / AI answer can flip them.
     */
    public function parse(array $rows, array $mapping, string $defaultOrder = 'dmy'): array
    {
        $start = is_int($mapping['header'] ?? null) ? $mapping['header'] + 1 : 0;
        $data = array_slice($rows, $start, null, true);
        $evidence = is_string($mapping['date_order'] ?? null) ? $mapping['date_order'] : $this->dateEvidence($data, $mapping['date']);
        $order = $evidence ?? $defaultOrder;
        $samples = $evidence === null ? $this->dateSamples($data, $mapping['date']) : [];
        $ambiguous = $samples !== [];
        $other = $order === 'mdy' ? 'dmy' : 'mdy';
        $styles = [];
        foreach (['amount', 'debit', 'credit'] as $field) {
            if (is_int($mapping[$field] ?? null)) {
                $styles[$field] = $this->decimalStyle($data, $mapping[$field]);
            }
        }

        $max = DocumentFile::maxRows();
        $out = [];
        $skipped = [];
        $capped = false;

        foreach ($data as $index => $row) {
            $line = $index + 1;
            $dateCell = $row[$mapping['date']] ?? null;
            $at = $this->parseDate($dateCell, $order);
            $amount = $this->rowAmount($row, $mapping, $styles);

            if ($at === null && $amount === null) {
                // Footer / subtotal lines ("Total", "Balance at end") are not data.
                continue;
            }
            if ($at === null) {
                $skipped[] = ['line' => $line, 'reason' => 'no valid date'];

                continue;
            }
            if ($amount === null || round($amount, 2) == 0.0) {
                $skipped[] = ['line' => $line, 'reason' => $amount === null ? 'no valid amount' : 'zero amount'];

                continue;
            }
            if (count($out) >= $max) {
                $capped = true;
                break;
            }

            $note = is_int($mapping['note'] ?? null) ? $this->text($row[$mapping['note']] ?? null) : null;
            $tag = is_int($mapping['tag'] ?? null) ? $this->text($row[$mapping['tag']] ?? null) : null;
            $currency = is_int($mapping['currency'] ?? null) ? $this->text($row[$mapping['currency']] ?? null) : null;

            $out[] = [
                'line' => $line,
                'at' => $at,
                'amount' => round($amount, 2),
                'note' => $note !== null ? mb_substr($note, 0, self::NOTE_MAX) : null,
                'tag_titles' => $tag !== null
                    ? array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;|\/]/u', $tag) ?: []), static fn ($t) => $t !== '')))
                    : [],
                'currency' => $currency !== null ? mb_strtoupper(mb_substr($currency, 0, 10)) : null,
            ];
            if ($ambiguous) {
                $swap = $this->parseDate($dateCell, $other);
                if ($swap !== null && $swap !== $at) {
                    $out[array_key_last($out)]['swap'] = $swap;
                }
            }
        }

        return ['rows' => $out, 'skipped' => $skipped, 'capped' => $capped, 'ambiguous' => $ambiguous, 'order' => $order, 'samples' => $samples];
    }

    /**
     * Y-m-d, or null when the cell isn't a plausible date.
     */
    public function parseDate(mixed $value, string $order = 'dmy'): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $this->plausible((int) $value->format('Y')) ? $value->format('Y-m-d') : null;
        }
        if (is_int($value) || is_float($value)) {
            // Excel serial day (1900 system) from a CSV or an unformatted cell.
            if ($value >= 25000 && $value <= 80000) {
                return Carbon::create(1899, 12, 30)->addDays((int) floor($value))->toDateString();
            }

            return null;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})(?:$|[T\s])/u', $value, $m)) {
            return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})([-.\/])(\d{1,2})\2(\d{2}|\d{4})(?:$|[T\s,])/u', $value, $m)) {
            $year = (int) $m[4];
            $year = $year < 100 ? 2000 + $year : $year;
            $a = (int) $m[1];
            $b = (int) $m[3];
            // Dotted dates are always day-first.
            [$day, $month] = $m[2] !== '.' && $order === 'mdy' ? [$b, $a] : [$a, $b];

            return $this->ymd($year, $month, $day);
        }
        if (preg_match('/^\d{8}$/', $value)) {
            return $this->ymd((int) substr($value, 0, 4), (int) substr($value, 4, 2), (int) substr($value, 6, 2));
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value)) {
            return $this->parseDate((float) $value, $order);
        }
        // "26 Oct 2026", "Oct 26, 2026".
        if (preg_match('/\p{L}{3,}/u', $value) && preg_match('/\d{4}/', $value)) {
            try {
                $date = Carbon::parse($value);

                return $this->plausible($date->year) ? $date->toDateString() : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Signed number, or null when the cell isn't an amount.
     *
     * @param 'comma'|'dot' $style decimal separator of the column
     */
    public function parseAmount(mixed $value, string $style = 'dot'): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $s = trim($value);
        if ($s === '' || !preg_match('/\d/', $s)) {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/u', $s, $m)) {
            $negative = true;
            $s = $m[1];
        }
        $s = str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $s);
        if (preg_match('/^[^\d]*-/u', $s) || preg_match('/\d\s*-\s*\D*$/u', $s)) {
            $negative = true;
        }
        // Thousands spaces (regular, NBSP, thin), apostrophes; currency signs and codes.
        $s = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}\'’]/u', '', $s) ?? $s;
        $s = preg_replace('/[^\d.,]/u', '', $s) ?? $s;
        if ($s === '' || !preg_match('/^\d[\d.,]*$/', $s)) {
            return null;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false) {
            $decimal = substr_count($s, ',') > 1 || ($style === 'dot' && preg_match('/,\d{3}$/', $s)) ? null : ',';
        } elseif ($lastDot !== false) {
            $decimal = substr_count($s, '.') > 1 || ($style === 'comma' && preg_match('/\.\d{3}$/', $s)) ? null : '.';
        } else {
            $decimal = null;
        }

        $thousands = $decimal === ',' ? '.' : ($decimal === '.' ? ',' : null);
        if ($thousands !== null) {
            $s = str_replace($thousands, '', $s);
        }
        $s = $decimal === null ? str_replace([',', '.'], '', $s) : str_replace(',', '.', $s);
        if (!is_numeric($s)) {
            return null;
        }

        $number = (float) $s;

        return $negative ? -$number : $number;
    }

    /**
     * @param list<mixed> $row
     * @return array<string, int|null>|null
     */
    private function mapHeader(array $row): ?array
    {
        $mapping = array_fill_keys(self::FIELDS, null);

        foreach ($row as $column => $cell) {
            if (!is_string($cell)) {
                continue;
            }
            $name = $this->normalize($cell);
            if ($name === '' || mb_strlen($name) > 60) {
                continue;
            }
            $field = $this->fieldOf($name);
            if ($field !== null && $mapping[$field] === null) {
                $mapping[$field] = $column;
            }
        }

        if ($mapping['date'] === null || ($mapping['amount'] === null && $mapping['debit'] === null && $mapping['credit'] === null)) {
            return null;
        }

        return $mapping + ['date_order' => null];
    }

    private function fieldOf(string $name): ?string
    {
        $patterns = [
            'type' => '/^(type|тип|kind|direction|dr\/cr|cr\/dr|debit\/credit|credit\/debit|in\/out|transaction type|тип операції|тип операции)$/u',
            'date' => '/(^|\s)(date|дата|datum|fecha|day|posted|booking|value date|transaction date)(\s|$)|^дата/u',
            'ignore' => '/(balance|залишок|остаток|баланс|commission|fee|комісі|комисс|cashback|кешбе|кешбэ|rate|курс|mcc|id$|номер|number|time$|час$|время$)/u',
            'debit' => '/(debit|withdraw|outflow|outgoing|expense|spent|paid out|money out|витрат|видат|списан|расход|дебет)/u',
            'credit' => '/(credit|deposit|inflow|incoming|income|received|paid in|money in|надходж|зарахув|поповн|доход|приход|кредит)/u',
            'amount' => '/(amount|sum|total|value|price|betrag|importe|сума|сумма|вартість|стоимость)/u',
            'tag' => '/(tag|categor|категор|тег|мітк|метк|group|груп)/u',
            'note' => '/(desc|detail|note|memo|payee|merchant|narrative|comment|purpose|counterparty|name|title|reference|опис|описан|детал|призначен|назначен|коментар|комментар|отримувач|получател|контрагент|примітк|примечан|назва|название)/u',
            'currency' => '/(currency|валют|ccy|curr)/u',
        ];

        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $name)) {
                return $field === 'ignore' ? null : $field;
            }
        }

        return null;
    }

    private function normalize(string $cell): string
    {
        $name = mb_strtolower(trim($cell));
        $name = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^\p{L}\p{N}\/]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * @param array<int, list<mixed>> $data
     */
    public function dateEvidence(array $data, int $column): ?string
    {
        foreach ($data as $row) {
            $cell = $row[$column] ?? null;
            if (is_string($cell) && preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/]\d{2,4}/', trim($cell), $m)) {
                if ((int) $m[1] > 12) {
                    return 'dmy';
                }
                if ((int) $m[2] > 12) {
                    return 'mdy';
                }
            }
        }

        return null;
    }

    /**
     * Up to 8 "a/b/yyyy" values that read differently day-first vs month-first ([] = no ambiguity).
     *
     * @param array<int, list<mixed>> $data
     * @return list<string>
     */
    public function dateSamples(array $data, int $column): array
    {
        $samples = [];
        foreach ($data as $row) {
            $cell = $row[$column] ?? null;
            if (is_string($cell) && preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/]\d{2,4}/', trim($cell), $m) && $m[1] !== $m[2]) {
                $samples[trim($cell)] = true;
                if (count($samples) >= 8) {
                    break;
                }
            }
        }

        return array_map('strval', array_keys($samples));
    }

    /**
     * 'comma' when the column writes decimals with a comma (12,50), else 'dot'.
     *
     * @param array<int, list<mixed>> $data
     */
    private function decimalStyle(array $data, int $column): string
    {
        $comma = 0;
        $dot = 0;
        foreach ($data as $row) {
            $cell = $row[$column] ?? null;
            if (!is_string($cell)) {
                continue;
            }
            $cell = trim($cell, " \t\n\r\0\x0B()");
            if (preg_match('/,\d{1,2}(\D*)$/u', $cell)) {
                $comma++;
            } elseif (preg_match('/\.\d{1,2}(\D*)$/u', $cell)) {
                $dot++;
            }
        }

        return $comma > $dot ? 'comma' : 'dot';
    }

    /**
     * @param list<mixed> $row
     * @param array<string, int|string|null> $mapping
     * @param array<string, string> $styles
     */
    private function rowAmount(array $row, array $mapping, array $styles): ?float
    {
        if (is_int($mapping['amount'] ?? null)) {
            $amount = $this->parseAmount($row[$mapping['amount']] ?? null, $styles['amount'] ?? 'dot');
            if ($amount !== null) {
                $type = is_int($mapping['type'] ?? null) ? mb_strtolower((string) $this->text($row[$mapping['type']] ?? null)) : '';
                if ($type !== '' && preg_match('/^(debit|dr|d|expense|out|outgoing|withdrawal|payment|витрата|витрати|списання|видаток|расход|списание|-)$/u', $type)) {
                    return -abs($amount);
                }
                if ($type !== '' && preg_match('/^(credit|cr|c|income|in|incoming|deposit|надходження|зарахування|поповнення|дохід|доход|приход|зачисление|\+)$/u', $type)) {
                    return abs($amount);
                }

                return $amount;
            }
        }

        $debit = is_int($mapping['debit'] ?? null) ? $this->parseAmount($row[$mapping['debit']] ?? null, $styles['debit'] ?? 'dot') : null;
        $credit = is_int($mapping['credit'] ?? null) ? $this->parseAmount($row[$mapping['credit']] ?? null, $styles['credit'] ?? 'dot') : null;
        if ($debit === null && $credit === null) {
            return null;
        }

        return abs($credit ?? 0.0) - abs($debit ?? 0.0);
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    private function ymd(int $year, int $month, int $day): ?string
    {
        if (!$this->plausible($year) || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function plausible(int $year): bool
    {
        return $year >= 1990 && $year <= 2100;
    }
}
