<?php

namespace App\Actions\Agent\Documents;

use finfo;
use ZipArchive;

/**
 * Documents the assistant reads (web drawer and Telegram): what a file is, its limits, and the
 * zip checks for Office/ODF archives. The kind comes from the sniffed content AND the extension,
 * never from the client's MIME type alone. Macros are never run: DOCX/XLSX/ODS are read as XML
 * only, and macro-enabled formats (.docm, .xlsm) are refused.
 */
final class DocumentFile
{
    public const PDF = 'pdf';

    public const DOCX = 'docx';

    public const CSV = 'csv';

    public const XLSX = 'xlsx';

    public const ODS = 'ods';

    public const JSON = 'json';

    public const OFX = 'ofx';

    public const QIF = 'qif';

    public const HTML = 'html';

    /** Kinds read as plain text (no zip, no binary). */
    public const TEXT_KINDS = [self::CSV, self::JSON, self::OFX, self::QIF, self::HTML];

    /** Canonical MIME stored with the upload, per kind. */
    public const MIMES = [
        self::PDF => 'application/pdf',
        self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        self::CSV => 'text/csv',
        self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        self::ODS => 'application/vnd.oasis.opendocument.spreadsheet',
        self::JSON => 'application/json',
        self::OFX => 'application/x-ofx',
        self::QIF => 'application/qif',
        self::HTML => 'text/html',
    ];

    /** Extensions people send, per kind. */
    public const EXTENSIONS = [
        'pdf' => self::PDF,
        'docx' => self::DOCX,
        'csv' => self::CSV,
        'tsv' => self::CSV,
        'txt' => self::CSV,
        'xlsx' => self::XLSX,
        'ods' => self::ODS,
        'json' => self::JSON,
        'ofx' => self::OFX,
        'qfx' => self::OFX,
        'qif' => self::QIF,
        'html' => self::HTML,
        'htm' => self::HTML,
    ];

    /** Known but unsupported: a friendly hint instead of "unsupported file". */
    private const LEGACY = [
        'xls' => 'Old Excel (.xls) files are not supported yet. Save it as .xlsx or .csv and send it again.',
        'xlsm' => 'Macro-enabled Excel files are not accepted. Save it as .xlsx or .csv and send it again.',
        'xlsb' => 'Binary Excel files are not supported. Save it as .xlsx or .csv and send it again.',
        'doc' => 'Old Word (.doc) files are not supported. Save it as .docx or PDF and send it again.',
        'docm' => 'Macro-enabled Word files are not accepted. Save it as .docx or PDF and send it again.',
        'rtf' => 'RTF files are not supported. Save it as .docx or PDF and send it again.',
        'numbers' => 'Numbers files are not supported. Export it as .csv or .xlsx and send it again.',
    ];

    public const UNSUPPORTED = 'Send a PDF, Word (.docx), CSV, Excel (.xlsx), OpenDocument (.ods), JSON, OFX / QFX, QIF or HTML file.';

    /** Zip entries an Office/ODF file may have (a real one has a few dozen). */
    private const MAX_ZIP_ENTRIES = 2000;

    /** Max uncompressed : compressed ratio of one big entry (XML compresses ~10-30x). */
    private const MAX_ZIP_RATIO = 200;

    /**
     * Whether a file name looks like a document the assistant reads (or one it explains it can't).
     */
    public static function looksLikeDocument(string $name): bool
    {
        $ext = self::extension($name);

        return isset(self::EXTENSIONS[$ext]) || isset(self::LEGACY[$ext]);
    }

    /**
     * The "save it as .xlsx / .docx" hint for a known unsupported format, else null.
     */
    public static function legacyHint(string $name): ?string
    {
        return self::LEGACY[self::extension($name)] ?? null;
    }

    public static function extension(string $name): string
    {
        return mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    }

    public static function maxBytes(string $kind): int
    {
        return $kind === self::PDF
            ? (int) config('agent.documents.max_pdf_bytes', 8 * 1024 * 1024)
            : (int) config('agent.documents.max_file_bytes', 5 * 1024 * 1024);
    }

    /** Documents in one message (web) or one Telegram group, merged into one import. */
    public static function maxFiles(): int
    {
        return max(1, (int) config('agent.documents.max_files', 5));
    }

    /** All documents of one message together. */
    public static function maxTotalBytes(): int
    {
        return max(1, (int) config('agent.documents.max_total_bytes', 20 * 1024 * 1024));
    }

    public static function maxRows(): int
    {
        return max(1, (int) config('agent.documents.max_rows', 1000));
    }

    public static function kindOfMime(string $mime): ?string
    {
        $kind = array_search($mime, self::MIMES, true);

        return is_string($kind) ? $kind : null;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /**
     * @return array{kind: string}|array{error: string}
     */
    public static function detect(string $bytes, string $name): array
    {
        $ext = self::extension($name);

        if (isset(self::LEGACY[$ext])) {
            return ['error' => self::LEGACY[$ext]];
        }

        if ($bytes === '') {
            return ['error' => 'The file is empty.'];
        }

        $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $kind = self::EXTENSIONS[$ext] ?? null;

        // Telegram and some pickers drop the extension: trust a clear PDF signature alone.
        if ($kind === null && $sniffed === 'application/pdf') {
            $kind = self::PDF;
        }

        // A .txt (or a file without an extension) may really be an OFX / QIF / JSON / HTML export.
        if (($kind === null || $ext === 'txt') && $sniffed !== 'application/pdf' && self::isText($bytes, $sniffed)) {
            $kind = self::sniffTextKind($bytes) ?? $kind;
        }

        $ok = match ($kind) {
            self::PDF => $sniffed === 'application/pdf' && str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-'),
            self::DOCX => self::isZip($bytes) && self::zipHas($bytes, ['word/document.xml'], ['word/vbaProject.bin']),
            self::XLSX => self::isZip($bytes) && self::zipHas($bytes, ['xl/workbook.xml'], ['xl/vbaProject.bin']),
            self::ODS => self::isZip($bytes) && self::odsMimetype($bytes),
            self::CSV => self::isText($bytes, $sniffed),
            self::JSON => self::isText($bytes, $sniffed) && self::sniffTextKind($bytes) === self::JSON,
            self::OFX => self::isText($bytes, $sniffed) && self::sniffTextKind($bytes) === self::OFX,
            self::QIF => self::isText($bytes, $sniffed) && self::sniffTextKind($bytes) === self::QIF,
            self::HTML => self::isText($bytes, $sniffed),
            default => false,
        };

        if ($kind === null) {
            return ['error' => self::UNSUPPORTED];
        }

        if (!$ok) {
            return ['error' => 'This file does not look like a valid .' . ($ext !== '' ? $ext : $kind) . ' file.'];
        }

        if (strlen($bytes) > self::maxBytes($kind)) {
            return ['error' => 'The file is larger than ' . self::formatBytes(self::maxBytes($kind)) . '.'];
        }

        if (in_array($kind, [self::DOCX, self::XLSX, self::ODS], true) && !self::zipIsSafe($bytes)) {
            return ['error' => 'This file is too large to open once unpacked.'];
        }

        return ['kind' => $kind];
    }

    /**
     * Zip bomb guard: entry count, total declared uncompressed size, and per-entry ratio.
     * Readers also cap what they read, so a lying header cannot blow memory either.
     */
    public static function zipIsSafe(string $bytes): bool
    {
        return self::withZip($bytes, static function (ZipArchive $zip): bool {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                return false;
            }
            $limit = (int) config('agent.documents.max_unzipped_bytes', 60 * 1024 * 1024);
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return false;
                }
                $size = (int) $stat['size'];
                $compressed = max(1, (int) $stat['comp_size']);
                $total += $size;
                if ($total > $limit || ($size > 1024 * 1024 && $size / $compressed > self::MAX_ZIP_RATIO)) {
                    return false;
                }
            }

            return true;
        }) ?? false;
    }

    /**
     * Read one zip entry, at most $maxBytes of it.
     */
    public static function zipEntry(string $bytes, string $entry, int $maxBytes): ?string
    {
        return self::withZip($bytes, static function (ZipArchive $zip) use ($entry, $maxBytes): ?string {
            $stat = $zip->statName($entry);
            if ($stat === false || (int) $stat['size'] > $maxBytes) {
                return null;
            }
            $data = $zip->getFromName($entry, $maxBytes);

            return is_string($data) ? $data : null;
        });
    }

    private static function isZip(string $bytes): bool
    {
        return str_starts_with($bytes, "PK\x03\x04");
    }

    /**
     * @param list<string> $required
     * @param list<string> $forbidden macro parts: a renamed .docm / .xlsm is refused too
     */
    private static function zipHas(string $bytes, array $required, array $forbidden): bool
    {
        return self::withZip($bytes, static function (ZipArchive $zip) use ($required, $forbidden): bool {
            foreach ($required as $name) {
                if ($zip->locateName($name) === false) {
                    return false;
                }
            }
            foreach ($forbidden as $name) {
                if ($zip->locateName($name) !== false) {
                    return false;
                }
            }

            return true;
        }) ?? false;
    }

    private static function odsMimetype(string $bytes): bool
    {
        return self::withZip($bytes, static fn (ZipArchive $zip): bool => trim((string) $zip->getFromName('mimetype', 200)) === self::MIMES[self::ODS]
            && $zip->locateName('content.xml') !== false
            && $zip->locateName('Basic/') === false) ?? false;
    }

    /**
     * OFX / QIF / JSON / HTML from the first bytes of a text file, else null (CSV or plain text).
     */
    public static function sniffTextKind(string $bytes): ?string
    {
        $head = ltrim(substr($bytes, 0, 4096), "\xEF\xBB\xBF \t\r\n");
        $lower = strtolower($head);
        if (str_starts_with($lower, 'ofxheader') || preg_match('/<\?ofx\b|<ofx>/', $lower)) {
            return self::OFX;
        }
        if (preg_match('/^!(type|account|option|clear)\b/i', $head)) {
            return self::QIF;
        }
        if ((str_starts_with($head, '{') || str_starts_with($head, '[')) && json_validate(self::stripBom($bytes), 64)) {
            return self::JSON;
        }
        if (preg_match('/<!doctype html|<html[\s>]|<table[\s>]|<body[\s>]/', $lower)) {
            return self::HTML;
        }

        return null;
    }

    public static function stripBom(string $bytes): string
    {
        return str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
    }

    private static function isText(string $bytes, string $sniffed): bool
    {
        if (str_contains(substr($bytes, 0, 65536), "\0")) {
            return false;
        }

        return str_starts_with($sniffed, 'text/')
            || in_array($sniffed, ['application/csv', 'application/x-csv', 'application/octet-stream', 'application/json', 'application/x-ofx', 'application/xml'], true);
    }

    /**
     * @template T
     * @param callable(ZipArchive): T $fn
     * @return T|null
     */
    private static function withZip(string $bytes, callable $fn): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'ffdoc');
        if ($path === false) {
            return null;
        }

        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
            try {
                return $fn($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }
}
