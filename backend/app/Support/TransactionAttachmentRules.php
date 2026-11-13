<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Single source of truth for attachment and photo formats: what can be attached (web, base64 API,
 * MCP, Telegram), what is stored, and what receipt vision reads. FileRules::forClient() sends
 * these to the web app; document/import formats live in DocumentFile.
 */
final class TransactionAttachmentRules
{
    public const MAX_PER_TRANSACTION = 5;

    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, string> stored mime => extension */
    public const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @var array<string, string> accepted, then stored as JPEG (ImageConverter) */
    public const CONVERTED_MIMES = [
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    /** @var array<string, string> photos (attachable images, incl. converted ones) */
    public const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    /** Formats the vision model reads directly; other photos are converted to JPEG first. */
    public const VISION_NATIVE_MIMES = ['image/jpeg', 'image/png'];

    /** File extensions people use for accepted files (validation, pickers). */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];

    /** Human list for messages. */
    public const LABEL = 'JPEG, PNG, WebP, HEIC and PDF';

    /**
     * Mimes accepted for an attachment (stored as is, or converted to JPEG).
     *
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        return [...array_keys(self::ALLOWED_MIMES), ...array_keys(self::CONVERTED_MIMES)];
    }

    public static function isAccepted(string $mime): bool
    {
        return isset(self::ALLOWED_MIMES[$mime]) || isset(self::CONVERTED_MIMES[$mime]);
    }

    public static function needsConversion(string $mime): bool
    {
        return isset(self::CONVERTED_MIMES[$mime]);
    }

    /**
     * Photos receipt vision can read: JPEG/PNG directly, WebP/HEIC once converted (only when the
     * converter is installed).
     *
     * @return list<string>
     */
    public static function visionMimes(): array
    {
        return ImageConverter::available() ? array_keys(self::IMAGE_MIMES) : self::VISION_NATIVE_MIMES;
    }

    /** Mime for an extension (".HEIC" from a phone with no mime type), or null. */
    public static function mimeForExtension(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'pdf' => 'application/pdf',
            default => null,
        };
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

    /** "IMG_0042.HEIC" → "IMG_0042.jpg" after conversion. */
    public static function jpegName(string $name): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);

        return ($stem !== '' ? $stem : 'photo') . '.jpg';
    }

    /**
     * Real type of an accepted file from its bytes (the claimed type only breaks a tie for formats
     * finfo doesn't know). Null when it isn't an accepted type.
     */
    public static function detectMime(string $contents, ?string $claimed = null): ?string
    {
        if (self::isHeic($contents)) {
            return 'image/heic';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents) ?: null;

        if ($detected && self::isAccepted($detected)) {
            return $detected === 'image/heif' ? 'image/heic' : $detected;
        }

        if ($claimed && isset(self::ALLOWED_MIMES[$claimed])) {
            return $claimed;
        }

        return null;
    }

    /** ISO-BMFF "ftyp" box with a HEIC/HEIF brand (iPhone photos). */
    public static function isHeic(string $contents): bool
    {
        if (strlen($contents) < 12 || substr($contents, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(substr($contents, 8, 4), ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'], true);
    }
}
