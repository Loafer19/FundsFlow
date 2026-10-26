<?php

namespace App\Actions\Agent\Documents;

use DateTimeInterface;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Rows of a CSV / XLSX / ODS file as plain cell values (first sheet only). CSV: UTF-8 with or
 * without BOM, UTF-16 BOM, or Windows-1251; delimiter ; , tab or | is detected. Reading stops a
 * little past the import row cap, so a huge sheet costs nothing beyond that.
 */
final class TableReader
{
    /** Leading rows (titles, account info) a statement may have above its header. */
    private const HEADER_SLACK = 30;

    /** Cells per row kept. */
    private const MAX_COLUMNS = 40;

    /** Characters per cell kept. */
    private const MAX_CELL_CHARS = 500;

    /**
     * @return array{rows: list<list<string|float|int|DateTimeInterface|null>>, delimiter: ?string, truncated: bool}|array{error: string}
     */
    public function read(string $kind, string $bytes): array
    {
        $limit = DocumentFile::maxRows() + self::HEADER_SLACK + 1;

        try {
            return match ($kind) {
                DocumentFile::CSV => $this->csv($bytes, $limit),
                DocumentFile::XLSX, DocumentFile::ODS => $this->sheet($kind, $bytes, $limit),
                default => ['error' => DocumentFile::UNSUPPORTED],
            };
        } catch (Throwable) {
            return ['error' => "Couldn't read this spreadsheet. Save it again as .xlsx or .csv and resend it."];
        }
    }

    /**
     * @return array{rows: list<list<string|null>>, delimiter: string, truncated: bool}|array{error: string}
     */
    private function csv(string $bytes, int $limit): array
    {
        $text = $this->toUtf8($bytes);
        if ($text === null) {
            return ['error' => "Couldn't read the text encoding of this CSV. Save it as UTF-8 and resend it."];
        }

        $delimiter = $this->delimiter($text);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $rows = [];
        $truncated = false;
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (count($rows) >= $limit) {
                $truncated = true;
                break;
            }
            $rows[] = $this->cells($row);
        }
        fclose($stream);

        return ['rows' => $this->dropEmpty($rows), 'delimiter' => $delimiter, 'truncated' => $truncated];
    }

    /**
     * @return array{rows: list<list<string|float|int|DateTimeInterface|null>>, delimiter: null, truncated: bool}
     */
    private function sheet(string $kind, string $bytes, int $limit): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ffdoc');
        if ($path === false) {
            return ['rows' => [], 'delimiter' => null, 'truncated' => false];
        }
        $file = $path . '.' . $kind;
        file_put_contents($file, $bytes);

        $reader = $kind === DocumentFile::XLSX ? new XlsxReader : new OdsReader;
        $rows = [];
        $truncated = false;

        try {
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if (count($rows) >= $limit) {
                        $truncated = true;
                        break;
                    }
                    $rows[] = $this->cells($row->toArray());
                }
                break;
            }
        } finally {
            $reader->close();
            @unlink($file);
            @unlink($path);
        }

        return ['rows' => $this->dropEmpty($rows), 'delimiter' => null, 'truncated' => $truncated];
    }

    /**
     * @param array<int, mixed> $row
     * @return list<string|float|int|DateTimeInterface|null>
     */
    private function cells(array $row): array
    {
        $cells = [];
        foreach (array_slice(array_values($row), 0, self::MAX_COLUMNS) as $value) {
            $cells[] = match (true) {
                $value === null, $value === '' => null,
                $value instanceof DateTimeInterface, is_int($value), is_float($value) => $value,
                is_bool($value) => $value ? '1' : '0',
                is_string($value) => trim($value) === '' ? null : mb_substr(trim($value), 0, self::MAX_CELL_CHARS),
                default => null,
            };
        }

        return $cells;
    }

    /**
     * @param list<list<mixed>> $rows
     * @return list<list<mixed>>
     */
    private function dropEmpty(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => array_filter($row, static fn ($cell) => $cell !== null) !== []));
    }

    private function toUtf8(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');

            return is_string($converted) ? $converted : null;
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        // Excel "CSV" on Ukrainian/Russian Windows.
        $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');

        return is_string($converted) && $converted !== '' ? $converted : null;
    }

    /**
     * The delimiter that splits the first lines into the same, largest number of columns.
     */
    private function delimiter(string $text): string
    {
        $lines = array_slice(array_values(array_filter(preg_split('/\R/', substr($text, 0, 20000)) ?: [], static fn ($l) => trim($l) !== '')), 0, 15);
        $best = ',';
        $bestScore = 0;

        foreach ([';', ',', "\t", '|'] as $candidate) {
            $counts = array_map(static fn (string $line): int => count(str_getcsv($line, $candidate, '"', '')), $lines);
            if ($counts === []) {
                continue;
            }
            $frequency = array_count_values($counts);
            arsort($frequency);
            $columns = (int) array_key_first($frequency);
            $score = $columns > 1 ? $columns * $frequency[$columns] : 0;
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
