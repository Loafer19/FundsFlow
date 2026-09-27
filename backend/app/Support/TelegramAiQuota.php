<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Shared daily AI budget for Telegram (receipt vision + text intents + voice STT).
 * Keyed by authenticated User id + calendar day in the user's timezone.
 */
final class TelegramAiQuota
{
    public function dailyLimit(): int
    {
        return max(0, (int) config('services.ai_receipt.daily_limit', 5));
    }

    public function used(User $user): int
    {
        return max(0, (int) Cache::get($this->key($user), 0));
    }

    public function remaining(User $user): int
    {
        return max(0, $this->dailyLimit() - $this->used($user));
    }

    public function hasQuota(User $user): bool
    {
        return $this->remaining($user) > 0;
    }

    /**
     * Next midnight in the user's timezone (when the daily counter rolls over).
     */
    public function resetAt(User $user): \Carbon\Carbon
    {
        return $user->nowInTimezone()->copy()->startOfDay()->addDay();
    }

    /**
     * Snapshot for Settings / API (same counters Telegram bot uses).
     *
     * @return array{used: int, limit: int, remaining: int, reset_at: string, timezone: string}
     */
    public function snapshot(User $user): array
    {
        $limit = $this->dailyLimit();
        $used = min($this->used($user), $limit);
        $resetAt = $this->resetAt($user);

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'reset_at' => $resetAt->toIso8601String(),
            'timezone' => $user->timezone(),
        ];
    }

    /**
     * Count a successful AI call toward the daily limit (user calendar day).
     */
    public function consume(User $user): void
    {
        $key = $this->key($user);
        $used = (int) Cache::get($key, 0);
        // Keep until well after the user's calendar day rolls over.
        Cache::put($key, $used + 1, now()->addDays(2));
    }

    public function key(User $user): string
    {
        return 'telegram_ai:' . $user->id . ':' . $user->todayDateString();
    }
}
