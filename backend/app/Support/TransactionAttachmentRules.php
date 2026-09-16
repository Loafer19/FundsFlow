<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TransactionAttachmentRules
{
    public const MAX_PER_TRANSACTION = 5;


    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, string> mime => extension */
    public const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        return array_keys(self::ALLOWED_MIMES);
    }

    public static function extensionForMime(string $mime): ?string
    {
        return self::ALLOWED_MIMES[$mime] ?? null;
    }

    public static function fallbackFileName(): string
    {
        return 'attachment-' . Str::lower(Str::random(8));
    }

    public static function sanitizeOriginalName(string $name): string
    {

        $clean = (string) Str::of($name)
            ->replace(["\0", "\r", "\n"], '')
            ->trim()
            ->limit(255, '');

        return $clean !== '' ? $clean : self::fallbackFileName();
    }

    public static function detectMime(string $contents, ?string $claimed = null): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents) ?: null;

        if ($detected && isset(self::ALLOWED_MIMES[$detected])) {
            return $detected;
        }

        if ($claimed && isset(self::ALLOWED_MIMES[$claimed])) {
            return $claimed;
        }

        return null;
    }
}



