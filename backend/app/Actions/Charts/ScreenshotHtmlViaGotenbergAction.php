<?php

namespace App\Actions\Charts;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ScreenshotHtmlViaGotenbergAction
{
    /**
     * Render a self-contained HTML page to PNG via Gotenberg Chromium screenshot route.
     *
     * @throws RuntimeException
     */
    public function execute(string $html, int $width = 1000, int $height = 620): string
    {
        $base = rtrim((string) config('services.gotenberg.url'), '/');

        if ($base === '') {
            throw new RuntimeException('Gotenberg URL is not configured.');
        }

        $response = Http::timeout((int) config('telegram.timeouts.gotenberg_screenshot'))
            ->connectTimeout((int) config('telegram.timeouts.gotenberg_connect'))
            ->attach('files', $html, 'index.html')
            ->post($base.'/forms/chromium/screenshot/html', [
                'width' => (string) $width,
                'height' => (string) $height,
                'clip' => 'true',
                'format' => 'png',
                'optimizeForSpeed' => 'true',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gotenberg screenshot failed (HTTP '.$response->status().'): '.mb_substr(trim($response->body()), 0, 300));
        }

        $png = $response->body();

        if (! str_starts_with($png, "\x89PNG")) {
            throw new RuntimeException('Gotenberg returned an invalid PNG.');
        }

        return $png;
    }
}
