<?php

namespace App\Actions\Report;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ConvertHtmlToPdfViaGotenbergAction
{
    /**
     * Convert a self-contained HTML document to PDF via Gotenberg Chromium.
     *
     * @throws RuntimeException
     */
    public function execute(string $html): string
    {
        $base = rtrim((string) config('services.gotenberg.url'), '/');

        if ($base === '') {
            throw new RuntimeException('Gotenberg URL is not configured.');
        }

        $response = Http::timeout(120)
            ->connectTimeout(10)
            ->attach('files', $html, 'index.html')
            ->post($base.'/forms/chromium/convert/html', [
                'landscape' => 'true',
                'printBackground' => 'true',
                'emulatedMediaType' => 'print',
                'preferCssPageSize' => 'true',
                'marginTop' => '0.2',
                'marginBottom' => '0.2',
                'marginLeft' => '0.2',
                'marginRight' => '0.2',
            ]);

        if (! $response->successful()) {
            $body = trim($response->body());
            $hint = $body !== '' ? mb_substr($body, 0, 300) : 'no body';

            throw new RuntimeException('Gotenberg PDF conversion failed (HTTP '.$response->status().'): '.$hint);
        }

        $pdf = $response->body();

        if ($pdf === '' || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Gotenberg returned an empty or invalid PDF.');
        }

        return $pdf;
    }
}
