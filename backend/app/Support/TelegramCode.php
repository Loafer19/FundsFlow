<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * One-time codes shared by the Telegram link flow (web → bot, /start payload)
 * and the /website login flow (bot → web), so both look and parse the same.
 *
 * 8 chars from 31 unambiguous symbols (no 0/O/1/I/L) ≈ 8.5e11 variants,
 * shown as K7QM-2XPA and accepted in any case, with or without dash/spaces.
 */
class TelegramCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    private const MAX_ATTEMPTS = 5;

    /**
     * Store $value under "{$prefix}:{code}" and return the code. Cache::add never
     * replaces a live entry, so a collision retries instead of hijacking someone's code.
     */
    public static function issue(string $prefix, mixed $value, DateTimeInterface $expiresAt): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::generate();

            if (Cache::add("{$prefix}:{$code}", $value, $expiresAt)) {
                return $code;
            }
        }

        throw new RuntimeException('Could not allocate a unique Telegram code.');
    }

    /** Pull (consume) the value stored for user input, or null when malformed/expired. */
    public static function pull(string $prefix, ?string $input): mixed
    {
        $code = self::normalize($input);

        return $code === null ? null : Cache::pull("{$prefix}:{$code}");
    }

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Canonical form (uppercase, no separators) or null if it cannot be a valid code. */
    public static function normalize(?string $input): ?string
    {
        $code = strtoupper(preg_replace('/[\s\-_]+/', '', (string) $input) ?? '');

        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) === 1 ? $code : null;
    }

    /** Human display: K7QM-2XPA. */
    public static function format(string $code): string
    {
        return implode('-', str_split($code, intdiv(self::LENGTH, 2)));
    }
}
