<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Shared daily AI budget for Telegram (receipt vision + text intents).
 */
final class TelegramAiQuota
{
    public function dailyLimit(): int
    {
        return max(0, (int) config('services.ai_receipt.daily_limit', 5));
    }

    public function remaining(User $user): int
    {
        $used = (int) Cache::get($this->key($user), 0);

        return max(0, $this->dailyLimit() - $used);
    }

    public function hasQuota(User $user): bool
    {
        return $this->remaining($user) > 0;
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
