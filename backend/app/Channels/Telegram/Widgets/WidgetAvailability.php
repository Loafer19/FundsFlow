<?php

namespace App\Channels\Telegram\Widgets;

use Illuminate\Support\Facades\Cache;

/**
 * Telegram can refuse widgets for the whole bot (since 13.11.2026 general bots get
 * INPUT_MEDIA_WIDGET_NOT_ALLOWED even in private chats). After such a refusal the bot stops
 * offering widget-only buttons for a while and uses its text flows (tag picker, typed amount),
 * then probes again once the flag expires. Rich messages (the step between a widget and classic
 * HTML) get the same treatment when the Bot API rejects the method itself.
 */
final class WidgetAvailability
{
    public const NOT_ALLOWED = 'INPUT_MEDIA_WIDGET_NOT_ALLOWED';

    private const KEY = 'telegram_widgets_blocked';

    private const RICH_KEY = 'telegram_rich_blocked';

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
     * A refusal for the whole bot (not this chat or this payload): the widget method is
     * not allowed or unknown.
     *
     * @param array<string, mixed> $result Bot API answer
     */
    public static function isNotAllowed(array $result): bool
    {
        return str_contains((string) ($result['description'] ?? ''), self::NOT_ALLOWED)
            || (int) ($result['error_code'] ?? 0) === 404;
    }

    public static function richBlocked(): bool
    {
        return !config('telegram.rich_messages', true) || Cache::get(self::RICH_KEY) === true;
    }

    /**
     * After a failed rich send: an unknown method (404) or a rejected rich_message parameter
     * turns rich messages off for a while. Any other error only falls back for that message.
     *
     * @param array<string, mixed> $result Bot API answer
     */
    public static function noteRichFailure(array $result): void
    {
        $description = strtolower((string) ($result['description'] ?? ''));

        if ((int) ($result['error_code'] ?? 0) === 404 || str_contains($description, 'rich_message') || str_contains($description, 'not allowed')) {
            Cache::put(self::RICH_KEY, true, now()->addMinutes(self::TTL_MINUTES));
        }
    }
}
