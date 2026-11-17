<?php

namespace App\Channels\Telegram\Widgets;

use Illuminate\Support\Facades\Cache;

/**
 * Telegram can refuse widgets for the whole bot (since 13.11.2026 general bots get
 * INPUT_MEDIA_WIDGET_NOT_ALLOWED even in private chats). After such a refusal the bot stops
 * offering widget-only buttons for a while and uses its text flows (tag picker, typed amount),
 * then probes again once the flag expires.
 */
final class WidgetAvailability
{
    public const NOT_ALLOWED = 'INPUT_MEDIA_WIDGET_NOT_ALLOWED';

    private const KEY = 'telegram_widgets_blocked';

    private const TTL_MINUTES = 60;

    public static function blocked(): bool
    {
        return Cache::get(self::KEY) === true;
    }

    public static function markBlocked(): void
    {
        Cache::put(self::KEY, true, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function clear(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * @param array<string, mixed> $result Bot API answer
     */
    public static function isNotAllowed(array $result): bool
    {
        return str_contains((string) ($result['description'] ?? ''), self::NOT_ALLOWED);
    }
}
