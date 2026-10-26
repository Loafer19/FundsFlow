<?php

namespace App\Actions\Agent\Documents;

use App\Models\User;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Text exports read without AI: JSON (a FundsFlow account export, or any array of objects),
 * OFX / QFX (SGML 1.x and XML 2.x), QIF, and HTML statements. Each one ends as either
 *  - rows: ready transactions {line, at, amount, note, tags, cur?, swap?} (FundsFlow JSON, OFX, QIF), or
 *  - table: cell rows for TableImport (generic JSON, an HTML table), or
 *  - text: plain text for the AI (an HTML page without a usable table).
 */
final class FormatReader
{
    private const NOTE_MAX = 255;

    /** Keys of a JSON object that may hold the list of transactions. */
    private const LIST_KEYS = ['transactions', 'operations', 'items', 'data', 'records', 'rows', 'entries', 'results', 'statement', 'history'];

    public function __construct(
        private readonly TableImport $import,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>, capped: bool, ambiguous?: bool, order?: string, samples?: list<string>}
     *     |array{table: list<list<mixed>>}
     *     |array{error: string}
     */
    public function json(User $user, string $bytes, string $fileName): array
    {
        try {
            $data = json_decode(DocumentFile::stripBom($bytes), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            return ['error' => "{$fileName} is not valid JSON."];
        }
        if (!is_array($data)) {
            return ['error' => "{$fileName} has no list of transactions."];
        }

        // A FundsFlow export (Settings → Export): {"version": 1, "transactions": [{at, amount, note, tags: [{title}]}]}.
        if (isset($data['version'], $data['transactions']) && is_array($data['transactions']) && array_is_list($data['transactions'])) {
            $first = $data['transactions'][0] ?? null;
            if ($first === null || (is_array($first) && array_key_exists('amount', $first) && array_key_exists('at', $first))) {
                return $this->fundsFlowExport($user, $data['transactions']);
            }
        }

        $list = $this->findList($data);
        if ($list === null) {
            return ['error' => "Couldn't find a list of transactions in {$fileName}. It should be an array of objects with a date and an amount."];
        }

        // Keys of the objects become the header row: TableImport then maps them like CSV columns.
        $keys = [];
        foreach (array_slice($list, 0, 200) as $item) {
            foreach (array_keys($item) as $key) {
                $keys[(string) $key] = true;
                if (count($keys) >= 40) {
                    break 2;
                }
            }
        }
        $keys = array_keys($keys);
        $table = [array_map(static fn ($k) => str_replace('_', ' ', (string) $k), $keys)];
        foreach (array_slice($list, 0, DocumentFile::maxRows() + 1) as $item) {
            $table[] = array_map(fn ($key) => $this->scalar($item[$key] ?? null), $keys);
        }

        return ['table' => $table];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>, capped: bool}|array{error: string}
     */
    public function ofx(string $bytes, string $fileName): array
    {
        $text = $this->utf8($bytes);
        if (!preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $text, $blocks)) {
            return ['error' => "No transactions found in {$fileName} (no STMTTRN records)."];
        }
        $currency = preg_match('/<CURDEF>\s*([A-Z]{3})/i', $text, $m) ? strtoupper($m[1]) : null;

        $rows = [];
        $skipped = [];
        $seen = [];
        $capped = false;
        foreach ($blocks[1] as $i => $block) {
            $line = $i + 1;
            $field = fn (string $tag): ?string => $this->ofxField($block, $tag);
            $fitId = $field('FITID');
            if ($fitId !== null && isset($seen[$fitId])) {
                continue;
            }
            if ($fitId !== null) {
                $seen[$fitId] = true;
            }
            $date = $field('DTPOSTED') ?? $field('DTUSER');
            $at = $date !== null && preg_match('/^(\d{4})(\d{2})(\d{2})/', $date, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1])
                ? "{$d[1]}-{$d[2]}-{$d[3]}" : null;
            $amountRaw = $field('TRNAMT');
            $amount = $amountRaw !== null ? $this->import->parseAmount(str_replace(',', '.', $amountRaw), 'dot') : null;
            if ($at === null) {
                $skipped[] = ['line' => $line, 'reason' => 'no valid date'];

                continue;
            }
            if ($amount === null || round($amount, 2) == 0.0) {
                $skipped[] = ['line' => $line, 'reason' => $amount === null ? 'no valid amount' : 'zero amount'];

                continue;
            }
            if (count($rows) >= DocumentFile::maxRows()) {
                $capped = true;
                break;
            }
            $name = $field('NAME') ?? $this->ofxField((string) preg_replace('/.*<PAYEE>/is', '', $block), 'NAME');
            $memo = $field('MEMO');
            $note = $name !== null && $memo !== null && mb_stripos($name, $memo) === false && mb_stripos($memo, $name) === false
                ? "{$name} · {$memo}" : ($name ?? $memo);
            $origCurrency = $this->ofxField($block, 'CURSYM');
            $rows[] = [
                'line' => $line,
                'at' => $at,
                'amount' => round($amount, 2),
                'note' => $note !== null ? mb_substr($note, 0, self::NOTE_MAX) : null,
                'tags' => [],
                'cur' => $origCurrency !== null ? strtoupper($origCurrency) : $currency,
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'capped' => $capped];
    }

    /**
     * QIF: records end with "^"; D date, T/U amount, P payee, M memo, L category ("Food:Cafe" → tag "Cafe",
     * "[Account]" = a transfer, no tag). Dates are month-first by the QIF convention unless a day > 12
     * shows otherwise; then the 'swap' reading is kept so the user can flip it.
     *
     * @return array{rows: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>, capped: bool, ambiguous: bool, order: string, samples: list<string>}|array{error: string}
     */
    public function qif(string $bytes, string $fileName): array
    {
        $text = $this->utf8($bytes);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $records = [];
        $current = [];
        $inTransactions = false;
        $start = 0;
        foreach ($lines as $n => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '!') {
                $header = strtolower($line);
                $inTransactions = (bool) preg_match('/^!type:(bank|cash|ccard|oth a|oth l|invst)/', $header);
                $current = [];

                continue;
            }
            if (!$inTransactions) {
                continue;
            }
            if ($line === '^') {
                if ($current !== []) {
                    $records[] = ['line' => $start + 1] + $current;
                }
                $current = [];

                continue;
            }
            if ($current === []) {
                $start = $n;
            }
            $code = $line[0];
            $value = trim(substr($line, 1));
            // Split lines (S, E, $) repeat the codes: the record total (T) is what is imported.
            if (in_array($code, ['D', 'T', 'U', 'P', 'M', 'L'], true) && !isset($current[$code])) {
                $current[$code] = $value;
            }
        }
        if ($current !== []) {
            $records[] = ['line' => $start + 1] + $current;
        }
        if ($records === []) {
            return ['error' => "No transactions found in {$fileName} (no !Type:Bank / Cash / CCard records)."];
        }

        $dates = array_map(fn (array $r) => [$this->qifDate((string) ($r['D'] ?? ''))], $records);
        $evidence = $this->import->dateEvidence($dates, 0);
        $order = $evidence ?? 'mdy';
        $samples = $evidence === null ? $this->import->dateSamples($dates, 0) : [];
        $other = $order === 'mdy' ? 'dmy' : 'mdy';

        $rows = [];
        $skipped = [];
        $capped = false;
        foreach ($records as $i => $record) {
            $at = $this->import->parseDate($dates[$i][0], $order);
            $amount = $this->import->parseAmount((string) ($record['T'] ?? $record['U'] ?? ''), $this->qifStyle((string) ($record['T'] ?? $record['U'] ?? '')));
            if ($at === null) {
                $skipped[] = ['line' => $record['line'], 'reason' => 'no valid date'];

                continue;
            }
            if ($amount === null || round($amount, 2) == 0.0) {
                $skipped[] = ['line' => $record['line'], 'reason' => $amount === null ? 'no valid amount' : 'zero amount'];

                continue;
            }
            if (count($rows) >= DocumentFile::maxRows()) {
                $capped = true;
                break;
            }
            $payee = isset($record['P']) && $record['P'] !== '' ? $record['P'] : null;
            $memo = isset($record['M']) && $record['M'] !== '' ? $record['M'] : null;
            $note = $payee !== null && $memo !== null && $payee !== $memo ? "{$payee} · {$memo}" : ($payee ?? $memo);
            $category = (string) ($record['L'] ?? '');
            $category = trim(explode('/', $category)[0]);
            $tag = $category !== '' && !str_starts_with($category, '[') ? trim((string) last(explode(':', $category))) : '';

            $row = [
                'line' => $record['line'],
                'at' => $at,
                'amount' => round($amount, 2),
                'note' => $note !== null ? mb_substr($note, 0, self::NOTE_MAX) : null,
                'tags' => $tag !== '' ? [mb_substr($tag, 0, 60)] : [],
            ];
            if ($samples !== []) {
                $swap = $this->import->parseDate($dates[$i][0], $other);
                if ($swap !== null && $swap !== $at) {
                    $row['swap'] = $swap;
                }
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'capped' => $capped, 'ambiguous' => $samples !== [], 'order' => $order, 'samples' => $samples];
    }

    /**
     * The largest table whose header TableImport recognises, else the page text (for the AI).
     *
     * @return array{table: list<list<string>>}|array{text: string}|array{error: string}
     */
    public function html(string $bytes, string $fileName): array
    {
        $html = $this->utf8($bytes);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return ['error' => "Couldn't open {$fileName}."];
        }

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $best = null;
        foreach ($dom->getElementsByTagName('table') as $table) {
            $rows = [];
            foreach ($xpath->query('./tr|./thead/tr|./tbody/tr|./tfoot/tr', $table) ?: [] as $tr) {
                $cells = [];
                foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
                    $cells[] = $this->cellText($cell);
                    $span = $cell instanceof DOMElement ? (int) $cell->getAttribute('colspan') : 1;
                    for ($k = 1; $k < min($span, 10); $k++) {
                        $cells[] = '';
                    }
                }
                if (array_filter($cells, static fn ($c) => $c !== '') !== []) {
                    $rows[] = $cells;
                }
                if (count($rows) > DocumentFile::maxRows() + 31) {
                    break;
                }
            }
            if (count($rows) >= 2 && $this->import->guessMapping($rows) !== null && ($best === null || count($rows) > count($best))) {
                $best = $rows;
            }
        }
        if ($best !== null) {
            return ['table' => $best];
        }

        $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        $text = $body !== null ? $this->blockText($body) : '';
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/u', ' ', $text)));
        if (preg_match_all('/[\p{L}\p{N}]/u', $text) < 5) {
            return ['error' => "{$fileName} has no text to read."];
        }

        return ['text' => $text];
    }

    /**
     * @param list<mixed> $transactions
     * @return array{rows: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>, capped: bool}
     */
    private function fundsFlowExport(User $user, array $transactions): array
    {
        $timezone = $user->timezone();
        $rows = [];
        $skipped = [];
        $capped = false;
        foreach ($transactions as $i => $tx) {
            $line = $i + 1;
            if (!is_array($tx)) {
                continue;
            }
            $at = null;
            if (is_string($tx['at'] ?? null)) {
                try {
                    $at = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tx['at']) ? $tx['at'] : Carbon::parse($tx['at'])->setTimezone($timezone)->toDateString();
                } catch (Throwable) {
                    $at = null;
                }
            }
            $amount = is_numeric($tx['amount'] ?? null) ? (float) $tx['amount'] : null;
            if ($at === null) {
                $skipped[] = ['line' => $line, 'reason' => 'no valid date'];

                continue;
            }
            if ($amount === null || round($amount, 2) == 0.0) {
                $skipped[] = ['line' => $line, 'reason' => $amount === null ? 'no valid amount' : 'zero amount'];

                continue;
            }
            if (count($rows) >= DocumentFile::maxRows()) {
                $capped = true;
                break;
            }
            $tags = [];
            foreach (is_array($tx['tags'] ?? null) ? $tx['tags'] : [] as $tag) {
                $title = is_array($tag) ? ($tag['title'] ?? null) : $tag;
                if (is_string($title) && trim($title) !== '') {
                    $tags[] = mb_substr(trim($title), 0, 60);
                }
            }
            $note = is_string($tx['note'] ?? null) && trim($tx['note']) !== '' ? mb_substr(trim($tx['note']), 0, self::NOTE_MAX) : null;
            $rows[] = ['line' => $line, 'at' => $at, 'amount' => round($amount, 2), 'note' => $note, 'tags' => array_values(array_unique($tags))];
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'capped' => $capped];
    }

    /**
     * The list of objects: the top level, or the first suitable value under a known key (or any key).
     *
     * @param array<mixed> $data
     * @return list<array<string, mixed>>|null
     */
    private function findList(array $data, int $depth = 0): ?array
    {
        $isObjects = static fn (mixed $v): bool => is_array($v) && $v !== [] && array_is_list($v)
            && count(array_filter(array_slice($v, 0, 20), static fn ($i) => is_array($i) && !array_is_list($i))) === count(array_slice($v, 0, 20));
        if ($isObjects($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if ($depth >= 2 || array_is_list($data)) {
            return null;
        }
        $keys = array_keys($data);
        usort($keys, static fn ($a, $b) => (int) !in_array(strtolower((string) $a), self::LIST_KEYS, true) <=> (int) !in_array(strtolower((string) $b), self::LIST_KEYS, true));
        foreach ($keys as $key) {
            if (is_array($data[$key])) {
                $found = $this->findList($data[$key], $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function scalar(mixed $value): string|float|int|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || $value === null) {
            return is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            // {"amount": {"value": -12.5, "currency": "EUR"}} or ["Food", "Cafe"].
            if (array_is_list($value)) {
                $parts = array_map(fn ($v) => is_array($v) ? ($v['title'] ?? $v['name'] ?? null) : $this->scalar($v), $value);

                return implode(', ', array_filter(array_map('strval', array_filter($parts, 'is_scalar'))));
            }
            foreach (['value', 'amount', 'title', 'name', 'date'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return $this->scalar($value[$key]);
                }
            }
        }

        return null;
    }

    private function ofxField(string $block, string $tag): ?string
    {
        if (!preg_match('/<' . $tag . '>([^<\r\n]*)/i', $block, $m)) {
            return null;
        }
        $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $value !== '' ? $value : null;
    }

    /**
     * "1/ 5'26" → "1/5/2026", "01/05/26" stays, ISO stays.
     */
    private function qifDate(string $value): string
    {
        $value = str_replace(' ', '', $value);
        if (preg_match("/^(\\d{1,2})[\\/.-](\\d{1,2})'(\\d{1,4})$/", $value, $m)) {
            $year = (int) $m[3];
            $year = $year < 100 ? 2000 + $year : $year;

            return "{$m[1]}/{$m[2]}/{$year}";
        }

        return $value;
    }

    private function qifStyle(string $amount): string
    {
        return preg_match('/,\d{1,2}$/', $amount) ? 'comma' : 'dot';
    }

    private function cellText(\DOMNode $cell): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $cell->textContent)));
    }

    /**
     * Text with line breaks at block elements, so rows of a layout stay on their own lines.
     */
    private function blockText(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= str_replace("\u{00A0}", ' ', (string) $child->textContent);

                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $name = strtolower($child->nodeName);
            $inner = $this->blockText($child);
            $out .= match (true) {
                in_array($name, ['td', 'th'], true) => $inner . "\t",
                in_array($name, ['br'], true) => "\n",
                in_array($name, ['p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'section', 'article', 'header', 'footer', 'dt', 'dd'], true) => "\n" . $inner . "\n",
                default => $inner,
            };
        }

        return $out;
    }

    private function utf8(string $bytes): string
    {
        $bytes = DocumentFile::stripBom($bytes);
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        // Old OFX / QIF / HTML exports: Windows-1251 (Cyrillic) is far more common here than Latin-1.
        $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');

        return is_string($converted) ? $converted : $bytes;
    }
}
