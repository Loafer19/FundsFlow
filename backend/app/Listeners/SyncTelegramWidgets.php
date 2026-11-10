<?php

namespace App\Listeners;

use App\Channels\Telegram\Widgets\TelegramWidgetSync;
use App\Events\TransactionsChanged;
use App\Jobs\SyncTelegramWidgetsJob;

/**
 * Refreshes open Telegram HTML widgets after a user's transactions change.
 *
 * The listener itself is not ShouldQueue: Laravel silently drops a queued
 * listener when shouldQueue() returns false (it does not fall through to
 * handle()). A dedicated job carries ShouldQueue / uniqueness instead.
 *
 * Today there is no Dokploy worker, so the job runs after the HTTP response
 * (same pattern as Telegram/Monobank webhooks). Set TELEGRAM_WIDGETS_QUEUE=true
 * once a worker is running with QUEUE_CONNECTION=database.
 */
class SyncTelegramWidgets
{
    /** @var array<int, true> */
    private static array $scheduled = [];

    public function handle(TransactionsChanged $event): void
    {
        $userId = $event->userId;

        if (self::queueEnabled()) {
            SyncTelegramWidgetsJob::dispatch($userId);

            return;
        }

        // afterResponse does not honor ShouldBeUnique, so coalesce per user
        // in this process (imports / bulk create fire many events at once).
        if (isset(self::$scheduled[$userId])) {
            return;
        }

        self::$scheduled[$userId] = true;

        dispatch(function () use ($userId) {
            try {
                (new SyncTelegramWidgetsJob($userId))->handle(app(TelegramWidgetSync::class));
            } finally {
                unset(self::$scheduled[$userId]);
            }
        })->afterResponse();
    }

    public static function queueEnabled(): bool
    {
        return (bool) config('telegram.widgets_queue')
            && config('queue.default') !== 'sync';
    }
}
