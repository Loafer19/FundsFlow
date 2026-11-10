<?php

namespace App\Services\Monobank;

class MerchantNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B-–—,.;:");

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 190);
    }
}
