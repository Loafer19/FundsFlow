<?php

namespace App\Support;

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
}
