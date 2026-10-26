<?php

namespace App\Actions\Agent\Documents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Plain text out of a PDF (poppler's pdftotext) or a DOCX (its word/document.xml), and the first
 * page of a scanned PDF as a JPEG for the receipt vision model. Nothing in the file is executed.
 */
final class DocumentText
{
    /** Below this many letters/digits a PDF counts as scanned (no text layer). */
    private const MIN_TEXT_CHARS = 20;

    /** Long side of the rasterized page, px (same as the web photo downscale). */
    private const RASTER_SIDE = 1600;

    /**
     * @return array{text: string}|array{scanned: true}|array{error: string}
     */
    public function pdf(string $bytes): array
    {
        $pages = max(1, (int) config('agent.documents.max_pdf_pages', 20));
        $out = $this->runOnTempFile($bytes, '.pdf', static fn (string $in): array => [
            'pdftotext', '-q', '-layout', '-enc', 'UTF-8', '-f', '1', '-l', (string) $pages, $in, '-',
        ]);

        if ($out === null) {
            return ['error' => "Couldn't read this PDF. If it is password-protected, remove the password or send a photo instead."];
        }

        $text = $this->clean($out);
        if (preg_match_all('/[\p{L}\p{N}]/u', $text) < self::MIN_TEXT_CHARS) {
            return ['scanned' => true];
        }

        return ['text' => $text];
    }

    /**
     * First page of a PDF as a JPEG (scanned receipts). Null when poppler fails.
     */
    public function pdfFirstPageJpeg(string $bytes): ?string
    {
        $dir = sys_get_temp_dir() . '/ffdoc-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700)) {
            return null;
        }
        $in = $dir . '/in.pdf';
        $outBase = $dir . '/page';

        try {
            file_put_contents($in, $bytes);
            $result = Process::timeout($this->timeout())->run([
                'pdftoppm', '-q', '-jpeg', '-jpegopt', 'quality=85', '-r', '150',
                '-scale-to', (string) self::RASTER_SIDE, '-f', '1', '-l', '1', '-singlefile', $in, $outBase,
            ]);
            if (!$result->successful() || !is_file($outBase . '.jpg')) {
                return null;
            }
            $jpeg = file_get_contents($outBase . '.jpg');

            return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
        } catch (Throwable $e) {
            Log::warning('DocumentText: pdftoppm failed', ['message' => $e->getMessage()]);

            return null;
        } finally {
            foreach ((array) glob($dir . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
    }

    /**
     * Paragraph text of a DOCX (tables become tab-separated lines). Null when unreadable.
     */
    public function docx(string $bytes): ?string
    {
        $limit = (int) config('agent.documents.max_unzipped_bytes', 60 * 1024 * 1024);
        $xml = DocumentFile::zipEntry($bytes, 'word/document.xml', $limit);
        if ($xml === null) {
            return null;
        }

        $xml = preg_replace('/<w:tab\b[^>]*\/>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:(br|cr)\b[^>]*\/>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:tc>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:(p|tr)>/', "\n", $xml) ?? $xml;
        // Deleted revisions and field codes are not visible text.
        $xml = preg_replace('/<w:(delText|instrText)\b[^>]*>.*?<\/w:\1>/s', '', $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $this->clean($text);
    }

    /**
     * Cap and tidy text before it goes to the model.
     */
    public function clean(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[^\S\n\t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function timeout(): int
    {
        return max(5, (int) config('agent.documents.tool_timeout', 30));
    }

    /**
     * @param callable(string): list<string> $command
     */
    private function runOnTempFile(string $bytes, string $suffix, callable $command): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'ffdoc');
        if ($path === false) {
            return null;
        }
        $in = $path . $suffix;

        try {
            file_put_contents($in, $bytes);
            $result = Process::timeout($this->timeout())->run($command($in));
            if (!$result->successful()) {
                Log::info('DocumentText: tool exit', ['code' => $result->exitCode(), 'error' => mb_substr($result->errorOutput(), 0, 300)]);

                return null;
            }

            return $result->output();
        } catch (Throwable $e) {
            Log::warning('DocumentText: tool failed', ['message' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($in);
            @unlink($path);
        }
    }
}
