<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Photo → JPEG with the ImageMagick CLI (config services.imagemagick): HEIC/HEIF attachments are
 * stored as JPEG, and WebP/HEIC photos are converted before receipt vision (the model reads JPEG
 * and PNG). EXIF orientation is applied, metadata stripped, and the long side capped.
 */
final class ImageConverter
{
    /** Longest side of a converted image, px (also keeps a JPEG well under 8 MB). */
    public const MAX_SIDE = 4096;

    /** Refuse images larger than this before decoding (pixel bombs). */
    private const MAX_PIXELS = '64MP';

    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        return self::$available = (bool) Cache::remember('image-converter:available:' . self::binary(), 3600, static function (): bool {
            try {
                $result = Process::timeout(10)->run([self::binary(), '-version']);

                return $result->successful() && str_contains($result->output(), 'ImageMagick');
            } catch (Throwable) {
                return false;
            }
        });
    }

    /** Reset the cached availability check (tests). */
    public static function forget(): void
    {
        self::$available = null;
        Cache::forget('image-converter:available:' . self::binary());
    }

    /**
     * JPEG bytes of an image (HEIC, HEIF, WebP, PNG, JPEG), or null when it can't be converted.
     */
    public static function toJpeg(string $bytes, string $mime, int $maxSide = self::MAX_SIDE, int $quality = 85): ?string
    {
        if ($bytes === '' || !self::available()) {
            return null;
        }

        $coder = match ($mime) {
            'image/heic', 'image/heif' => 'heic',
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            default => null,
        };

        if ($coder === null) {
            return null;
        }

        $base = tempnam(sys_get_temp_dir(), 'ffimg');

        if ($base === false) {
            return null;
        }
        $in = $base . '.' . $coder;
        $out = $base . '.jpg';

        try {
            file_put_contents($in, $bytes);
            // [0]: first frame / primary image only (HEIC bursts, animated WebP).
            $result = Process::timeout(max(5, (int) config('services.imagemagick.timeout', 30)))->run([
                self::binary(),
                '-limit', 'area', self::MAX_PIXELS,
                '-limit', 'memory', '256MiB',
                $coder . ':' . $in . '[0]',
                '-auto-orient',
                '-strip',
                '-resize', $maxSide . 'x' . $maxSide . '>',
                '-quality', (string) $quality,
                'jpeg:' . $out,
            ]);

            if (!$result->successful() || !is_file($out)) {
                Log::info('ImageConverter: convert failed', ['mime' => $mime, 'error' => mb_substr($result->errorOutput(), 0, 300)]);

                return null;
            }

            $jpeg = (string) file_get_contents($out);

            return str_starts_with($jpeg, "\xFF\xD8\xFF") ? $jpeg : null;
        } catch (Throwable $e) {
            Log::warning('ImageConverter: failed', ['message' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($in);
            @unlink($out);
            @unlink($base);
        }
    }

    /**
     * An image ready for the vision model: JPEG and PNG as they are, other photos converted to
     * JPEG. Null when it can't be read.
     *
     * @return null|array{bytes: string, mime: string}
     */
    public static function forVision(string $bytes, string $mime): ?array
    {
        if (in_array($mime, TransactionAttachmentRules::VISION_NATIVE_MIMES, true)) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        if (!in_array($mime, TransactionAttachmentRules::visionMimes(), true)) {
            return null;
        }

        $jpeg = self::toJpeg($bytes, $mime, 2048);

        return $jpeg === null ? null : ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
    }

    private static function binary(): string
    {
        return (string) config('services.imagemagick.binary', 'magick');
    }
}
