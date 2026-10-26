<?php

namespace App\Actions\Agent\Documents;

use App\Actions\Telegram\AnalyzeReceiptAction;
use App\Models\User;
use App\Support\TelegramAiQuota;

/**
 * Reads ONE file of "send the assistant a file" (web drawer and Telegram) into transaction rows:
 *  - CSV / XLSX / ODS, generic JSON, an HTML table: parsed locally; the AI only maps columns it
 *    can't recognise (1 assist).
 *  - FundsFlow JSON export, OFX / QFX, QIF: parsed locally, never AI.
 *  - PDF / DOCX / an HTML page without a table: text sent to the text model once (1 assist): a
 *    receipt becomes one draft, a statement becomes rows. A scanned PDF's first page goes to vision.
 * At most one AI assist per file. DocumentImport merges the rows of all files into one plan.
 */
final class ReadDocumentAction
{
    /** Rows sent to the AI to map unknown columns (a possible header + samples). */
    private const MAPPING_ROWS = 4;

    public function __construct(
        private readonly DocumentText $text,
        private readonly TableReader $tables,
        private readonly TableImport $import,
        private readonly FormatReader $formats,
        private readonly DocumentAi $ai,
        private readonly AnalyzeReceiptAction $vision,
        private readonly TelegramAiQuota $quota,
    ) {}

    /**
     * @param string $withoutAi tail of the "no AI assists left" message (channel specific)
     * @return array{type: 'error', message: string}
     *     |array{type: 'quota', message: string}
     *     |array{type: 'single', analysis: array{amount: ?float, at: ?string, note: ?string, suggested_tag_titles: list<string>, needs_price: bool}}
     *     |array{type: 'rows', file: array<string, mixed>, rows: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>, capped: bool, ai_used: bool}
     *
     * rows: {line, at, amount, note, tags: list<string>, soft?: true (AI-suggested tags), cur?: string, swap?: string}
     */
    public function execute(User $user, string $kind, string $bytes, string $fileName, ?string $caption, string $withoutAi): array
    {
        return match ($kind) {
            DocumentFile::CSV, DocumentFile::XLSX, DocumentFile::ODS => $this->spreadsheet($user, $kind, $bytes, $fileName),
            DocumentFile::JSON => $this->json($user, $bytes, $fileName),
            DocumentFile::OFX => $this->ready($fileName, $kind, $this->formats->ofx($bytes, $fileName)),
            DocumentFile::QIF => $this->ready($fileName, $kind, $this->formats->qif($bytes, $fileName)),
            DocumentFile::HTML => $this->html($user, $bytes, $fileName, $caption, $withoutAi),
            DocumentFile::PDF => $this->pdf($user, $bytes, $fileName, $caption, $withoutAi),
            DocumentFile::DOCX => $this->docx($user, $bytes, $fileName, $caption, $withoutAi),
            default => $this->error(DocumentFile::UNSUPPORTED),
        };
    }

    private function spreadsheet(User $user, string $kind, string $bytes, string $fileName): array
    {
        $read = $this->tables->read($kind, $bytes);
        if (isset($read['error'])) {
            return $this->error($read['error']);
        }

        return $this->table($user, $kind, $read['rows'], $fileName, $read['truncated']);
    }

    private function json(User $user, string $bytes, string $fileName): array
    {
        $read = $this->formats->json($user, $bytes, $fileName);
        if (isset($read['table'])) {
            return $this->table($user, DocumentFile::JSON, $read['table'], $fileName, count($read['table']) > DocumentFile::maxRows() + 1);
        }

        return $this->ready($fileName, DocumentFile::JSON, $read);
    }

    private function html(User $user, string $bytes, string $fileName, ?string $caption, string $withoutAi): array
    {
        $read = $this->formats->html($bytes, $fileName);
        if (isset($read['error'])) {
            return $this->error($read['error']);
        }
        if (isset($read['table'])) {
            return $this->table($user, DocumentFile::HTML, $read['table'], $fileName, false);
        }

        return $this->fromText($user, $read['text'], $fileName, $caption, $withoutAi, DocumentFile::HTML);
    }

    /**
     * Rows a format reader produced itself (FundsFlow JSON, OFX, QIF): no AI.
     *
     * @param array<string, mixed> $read
     */
    private function ready(string $fileName, string $kind, array $read): array
    {
        if (isset($read['error'])) {
            return $this->error($read['error']);
        }
        if ($read['rows'] === []) {
            return $this->error("No transactions found in {$fileName}.");
        }

        return $this->rows($fileName, $kind, $read['rows'], $read['skipped'], $read['capped'], false, [
            'ambiguous' => $read['ambiguous'] ?? false,
            'order' => $read['order'] ?? null,
            'samples' => $read['samples'] ?? [],
        ]);
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function table(User $user, string $kind, array $rows, string $fileName, bool $truncated): array
    {
        if ($rows === []) {
            return $this->error("{$fileName} has no rows.");
        }

        $aiUsed = false;
        $mapping = $this->import->guessMapping($rows);
        if ($mapping === null) {
            if (!$this->quota->hasQuota($user)) {
                return ['type' => 'quota', 'message' => $this->quota->exhaustedMessage(
                    $user,
                    "Until then, name the columns date, amount and note (see the CSV template in the help) and send the file again: that needs no AI.",
                )];
            }
            $sample = array_slice($rows, 0, self::MAPPING_ROWS);
            $proposed = $this->ai->mapColumns($sample, $this->dateLikeValues($rows));
            if ($proposed === null) {
                return $this->error("Couldn't recognise the columns of {$fileName}. Name them date, amount and note and send it again.");
            }
            $this->quota->consume($user);
            $aiUsed = true;
            $mapping = $this->import->acceptMapping($proposed, $rows, count($sample));
            if ($mapping === null) {
                return $this->error("Couldn't find date and amount columns in {$fileName}. Name them date and amount (and optionally note, tag) and send it again.");
            }
        }

        $parsed = $this->import->parse($rows, $mapping, $this->defaultDateOrder($user));
        if ($parsed['rows'] === []) {
            return $this->error("No transactions found in {$fileName}: no row has both a valid date and a non-zero amount.");
        }

        $out = array_map(static function (array $row): array {
            $item = ['line' => $row['line'], 'at' => $row['at'], 'amount' => $row['amount'], 'note' => $row['note'], 'tags' => $row['tag_titles']];
            if ($row['currency'] !== null) {
                $item['cur'] = $row['currency'];
            }
            if (isset($row['swap'])) {
                $item['swap'] = $row['swap'];
            }

            return $item;
        }, $parsed['rows']);

        return $this->rows($fileName, $kind, $out, $parsed['skipped'], $parsed['capped'] || $truncated, $aiUsed, [
            'ambiguous' => $parsed['ambiguous'],
            'order' => $parsed['order'],
            'samples' => $parsed['samples'],
        ]);
    }

    private function pdf(User $user, string $bytes, string $fileName, ?string $caption, string $withoutAi): array
    {
        $read = $this->text->pdf($bytes);
        if (isset($read['error'])) {
            return $this->error($read['error']);
        }

        if (isset($read['scanned'])) {
            if (!$this->quota->hasQuota($user)) {
                return ['type' => 'quota', 'message' => $this->quota->exhaustedMessage($user, $withoutAi)];
            }
            $jpeg = $this->text->pdfFirstPageJpeg($bytes);
            if ($jpeg === null) {
                return $this->error("This PDF is a scan without text and couldn't be read. Send a photo of the receipt instead.");
            }
            $analysis = $this->vision->execute($user, $jpeg, 'image/jpeg', $caption);
            if ($analysis === null) {
                return $this->error("Couldn't read this scanned PDF right now. Send a photo, or type the amount like -350 groceries.");
            }
            $this->quota->consume($user);

            return ['type' => 'single', 'analysis' => [
                'amount' => $analysis['amount'],
                'at' => $analysis['at'],
                'note' => $analysis['note'],
                'suggested_tag_titles' => $analysis['suggested_tag_titles'],
                'needs_price' => $analysis['needs_price'] || $analysis['amount'] === null,
            ]];
        }

        return $this->fromText($user, $read['text'], $fileName, $caption, $withoutAi, DocumentFile::PDF);
    }

    private function docx(User $user, string $bytes, string $fileName, ?string $caption, string $withoutAi): array
    {
        $text = $this->text->docx($bytes);
        if ($text === null) {
            return $this->error("Couldn't open {$fileName}. Save it again as .docx or PDF and resend it.");
        }
        if (preg_match_all('/[\p{L}\p{N}]/u', $text) < 5) {
            return $this->error("{$fileName} has no text to read.");
        }

        return $this->fromText($user, $text, $fileName, $caption, $withoutAi, DocumentFile::DOCX);
    }

    private function fromText(User $user, string $text, string $fileName, ?string $caption, string $withoutAi, string $kind): array
    {
        if (!$this->quota->hasQuota($user)) {
            return ['type' => 'quota', 'message' => $this->quota->exhaustedMessage($user, $withoutAi)];
        }

        $result = $this->ai->extractTransactions($user, $text, $fileName, $caption);
        if ($result === null) {
            return $this->error("Couldn't read transactions from {$fileName} right now. Try again later; for a long bank statement, export it as CSV or Excel instead.");
        }
        $this->quota->consume($user);

        $items = $result['transactions'];
        if ($result['kind'] === 'receipt' || count($items) === 1 || $result['needs_price']) {
            $first = $items[0] ?? null;
            if ($first === null && !$result['needs_price']) {
                return $this->error("No transactions found in {$fileName}.");
            }

            return ['type' => 'single', 'analysis' => [
                'amount' => $first['amount'] ?? null,
                'at' => $first['at'] ?? null,
                'note' => $first['note'] ?? null,
                'suggested_tag_titles' => $first['suggested_tag_titles'] ?? [],
                'needs_price' => $first === null,
            ]];
        }

        if ($items === []) {
            return $this->error("No transactions found in {$fileName}.");
        }

        $rows = [];
        $skipped = [];
        foreach ($items as $i => $item) {
            if ($item['at'] === null) {
                $skipped[] = ['line' => $i + 1, 'reason' => 'no date'];

                continue;
            }
            $rows[] = ['line' => $i + 1, 'at' => $item['at'], 'amount' => $item['amount'], 'note' => $item['note'], 'tags' => $item['suggested_tag_titles'], 'soft' => true];
        }
        if ($rows === []) {
            return $this->error("No dated transactions found in {$fileName}.");
        }

        return $this->rows($fileName, $kind, $rows, $skipped, count($items) >= DocumentFile::maxRows(), true, []);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{line: int, reason: string}> $skipped
     * @param array{ambiguous?: bool, order?: ?string, samples?: list<string>} $dates
     */
    private function rows(string $fileName, string $kind, array $rows, array $skipped, bool $capped, bool $aiUsed, array $dates): array
    {
        return [
            'type' => 'rows',
            'file' => [
                'name' => mb_substr($fileName, 0, 120),
                'kind' => $kind,
                'ambiguous' => (bool) ($dates['ambiguous'] ?? false),
                'order' => $dates['order'] ?? null,
                'samples' => $dates['samples'] ?? [],
            ],
            'rows' => $rows,
            'skipped' => $skipped,
            'capped' => $capped,
            'ai_used' => $aiUsed,
        ];
    }

    /**
     * "a/b/yyyy" values beyond the AI sample rows: they tell the model the date order.
     *
     * @param list<list<mixed>> $rows
     * @return list<string>
     */
    private function dateLikeValues(array $rows): array
    {
        $found = [];
        foreach (array_slice($rows, self::MAPPING_ROWS, 300) as $row) {
            foreach ($row as $cell) {
                if (is_string($cell) && preg_match('/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}/', trim($cell))) {
                    $found[trim($cell)] = true;
                }
            }
            if (count($found) >= 16) {
                break;
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * 03/04/2026 is read month-first only for users who write dates that way.
     */
    private function defaultDateOrder(User $user): string
    {
        return str_starts_with(strtoupper($user->dateFormat()), 'MM') ? 'mdy' : 'dmy';
    }

    /**
     * @return array{type: 'error', message: string}
     */
    private function error(string $message): array
    {
        return ['type' => 'error', 'message' => $message];
    }
}
