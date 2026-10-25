<?php

namespace App\Support;

/**
 * Receipt photo helpers shared by the Telegram bot and the web assistant.
 */
final class ReceiptFiles
{
    /**
     * Best photo of an album to send to vision: JPEG > PNG > WebP, then the largest.
     * Each file needs `mime` and (optionally) `file_size`; the winning element is returned as is.
     *
     * @template T of array<string, mixed>
     * @param non-empty-list<T> $files
     * @return T
     */
    public static function pickBest(array $files): array
    {
        $best = $files[0];
        $bestScore = -1;

        foreach ($files as $file) {
            $mime = (string) ($file['mime'] ?? '');
            $size = (int) ($file['file_size'] ?? 0);
            $mimeBonus = match ($mime) {
                'image/jpeg' => 1_000_000_000,
                'image/png' => 900_000_000,
                'image/webp' => 800_000_000,
                default => 0,
            };
            $score = $mimeBonus + $size;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $file;
            }
        }

        return $best;
    }
}
