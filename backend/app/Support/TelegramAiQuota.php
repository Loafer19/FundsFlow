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
    /**
     * Today's limit: the user's own `ai_daily_limit` (set in /admin) or AI_RECEIPT_DAILY_LIMIT,
     * plus any one-off bonus an admin granted for today. Without a user: the server default.
     */
    public function dailyLimit(?User $user = null): int
    {
        $base = $user?->ai_daily_limit ?? (int) config('services.ai_receipt.daily_limit', 5);

        return max(0, (int) $base) + ($user ? $this->bonus($user) : 0);
    }

    /** Server default (AI_RECEIPT_DAILY_LIMIT), ignoring per-user overrides. */
    public function defaultLimit(): int
    {
        return max(0, (int) config('services.ai_receipt.daily_limit', 5));
    }

    /** Extra assists an admin granted for the user's current day. */
    public function bonus(User $user): int
    {
        return max(0, (int) Cache::get($this->bonusKey($user), 0));
    }

    /** Admin: add assists for today only (gone at the user's midnight). */
    public function grantBonus(User $user, int $assists): void
    {
        Cache::put($this->bonusKey($user), $this->bonus($user) + max(0, $assists), now()->addDays(2));
    }

    /** Admin: zero today's counter (and drop today's bonus). */
    public function reset(User $user): void
    {
        Cache::forget($this->key($user));
        Cache::forget($this->bonusKey($user));
    }

    public function used(User $user): int
    {
        return max(0, (int) Cache::get($this->key($user), 0));
    }

    public function remaining(User $user): int
    {
        return max(0, $this->dailyLimit($user) - $this->used($user));
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
        $limit = $this->dailyLimit($user);
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
     * Human-readable reset time in the user's timezone (for bot messages).
     */
    public function formatResetLabel(User $user): string
    {
        return $this->resetAt($user)->format('M j, g:i A') . ' ' . $user->timezone();
    }

    /**
     * Hard-fail copy when remaining is 0 (receipt AI, NL agent, STT).
     * The counter rolls over at midnight in the user's timezone (see key()/resetAt()).
     *
     * @param string $withoutAi Channel-specific tail: what still works without AI there.
     */
    public function exhaustedMessage(User $user, string $withoutAi): string
    {
        $limit = $this->dailyLimit($user);
        $head = $limit > 0
            ? "You've used all {$limit} AI assists for today. They reset at midnight ({$user->timezone()})."
            : ($this->defaultLimit() > 0 ? 'AI assists are turned off for your account.' : 'AI assists are turned off on this server.');

        return trim($head . ' ' . $withoutAi);
    }

    /**
     * Short hint when remaining is low after a successful AI call. Null when remaining is high.
     */
    public function lowRemainingHint(User $user, ?int $threshold = null): ?string
    {
        $threshold ??= (int) config('telegram.low_quota_threshold');
        $snap = $this->snapshot($user);

        // Soft hint only while still usable; hard-fail at 0 uses exhaustedMessage().
        if ($snap['limit'] <= 0 || $snap['remaining'] < 1 || $snap['remaining'] > $threshold) {
            return null;
        }

        return "Heads up: {$snap['remaining']} of {$snap['limit']} AI assists left today (they reset at midnight, {$user->timezone()}).";
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

    public function bonusKey(User $user): string
    {
        return 'telegram_ai_bonus:' . $user->id . ':' . $user->todayDateString();
    }
}
