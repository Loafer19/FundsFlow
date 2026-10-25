<?php

namespace App\Actions\Report;

use App\Support\ReportPrintToken;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ConvertUrlToPdfViaGotenbergAction
{
    /** The print page sets this once data is applied and every Chart.js canvas has painted. */
    public const READY_EXPRESSION = 'window.__reportReady === true';

    /**
     * Open a URL in Gotenberg Chromium, wait for the report page to be ready, print it to PDF.
     * The page's @page CSS sets A4 landscape and margins (preferCssPageSize); the form values
     * are the A4 fallback. An uncaught page exception (the print page throws on load failure)
     * makes Gotenberg fail fast instead of printing an error screen.
     *
     * @throws RuntimeException message never contains the print token
     */
    public function execute(string $url): string
    {
        $base = rtrim((string) config('services.gotenberg.url'), '/');

        if ($base === '') {
            throw new RuntimeException('Gotenberg URL is not configured.');
        }

        $response = Http::timeout((int) config('telegram.timeouts.gotenberg_pdf'))
            ->connectTimeout((int) config('telegram.timeouts.gotenberg_connect'))
            ->asMultipart()
            ->post($base . '/forms/chromium/convert/url', [
                ['name' => 'url', 'contents' => $url],
                ['name' => 'waitForExpression', 'contents' => self::READY_EXPRESSION],
                ['name' => 'emulatedMediaType', 'contents' => 'print'],
                ['name' => 'printBackground', 'contents' => 'true'],
                ['name' => 'preferCssPageSize', 'contents' => 'true'],
                ['name' => 'landscape', 'contents' => 'true'],
                ['name' => 'paperWidth', 'contents' => '8.27'],
                ['name' => 'paperHeight', 'contents' => '11.69'],
                ['name' => 'marginTop', 'contents' => '0'],
                ['name' => 'marginBottom', 'contents' => '0'],
                ['name' => 'marginLeft', 'contents' => '0'],
                ['name' => 'marginRight', 'contents' => '0'],
                ['name' => 'failOnConsoleExceptions', 'contents' => 'true'],
            ]);

        if (! $response->successful()) {
            $body = trim($response->body());
            $hint = $body !== '' ? mb_substr($body, 0, 300) : 'no body';

            throw new RuntimeException(ReportPrintToken::redact('Gotenberg URL→PDF failed (HTTP ' . $response->status() . '): ' . $hint));
        }

        $pdf = $response->body();

        if ($pdf === '' || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Gotenberg returned an empty or invalid PDF.');
        }

        return $pdf;
    }
}
