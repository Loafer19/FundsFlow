<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Throwable;

/**
 * Quick-add text ("-350 groceries", "12.10 -40 taxi") shared by the Telegram bot
 * (messages and photo captions) and the web assistant (receipt photo captions).
 */
final class QuickAddParser
{
    public function parseDate(string $raw): ?string
    {
        $normalized = substr_count($raw, '.') === 1 ? $raw . '.' . now()->format('Y') : $raw;

        try {
            $date = Carbon::createFromFormat('d.m.Y', $normalized);
        } catch (Throwable) {
            return null;
        }

        if (!$date || $date->format('d.m.Y') !== $normalized) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * @return array{at: string, amount: float, note: ?string}|array{error: string}|null
     */
    public function parseQuickAdd(string $text, ?User $user = null): ?array
    {
        $date = $user?->todayDateString() ?? now()->toDateString();

        if (preg_match('/^(\d{1,2}\.\d{1,2}(?:\.\d{4})?)\s+(.+)$/u', $text, $dateMatch)) {
            $parsedDate = $this->parseDate($dateMatch[1]);

            if ($parsedDate === null) {
                return ['error' => "Couldn't parse that date. Use DD.MM or DD.MM.YYYY"];
            }

            $date = $parsedDate;
            $text = $dateMatch[2];
        }

        if (!preg_match('/^([+-]?\d+(?:[.,]\d{1,2})?)\s*(.*)$/u', $text, $matches)) {
            return null;
        }

        $amount = (float) str_replace(',', '.', $matches[1]);

        if (!str_starts_with($matches[1], '+') && !str_starts_with($matches[1], '-')) {
            $amount = -abs($amount);
        }

        if ($amount === 0.0) {
            return ['error' => "Amount can't be zero"];
        }

        $note = trim($matches[2]);

        if (mb_strlen($note) > 255) {
            return ['error' => 'Note is too long (255 characters max)'];
        }

        return [
            'at' => $date,
            'amount' => $amount,
            'note' => $note !== '' ? $note : null,
        ];
    }
}
