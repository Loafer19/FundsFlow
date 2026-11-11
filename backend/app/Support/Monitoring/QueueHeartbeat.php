<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Worker liveness: QueueHeartbeatJob (queued every minute) stamps the cache; check() runs
 * from the scheduler and alerts once when the stamp is stale, repeats while it stays stale,
 * and says when it's back. Needs a cache store shared by web, worker and scheduler (redis).
 */
class QueueHeartbeat
{
    public const KEY = 'queue:heartbeat';

    private const SINCE_KEY = 'queue:heartbeat:since';

    private const DOWN_KEY = 'queue:heartbeat:down';

    public function __construct(
        private readonly AdminAlerts $alerts,
    ) {}

    public static function beat(): void
    {
        Cache::put(self::KEY, now()->timestamp, now()->addDays(7));
    }

    /**
     * @return 'down'|'ok'|'recovered'|'waiting'
     */
    public function check(): string
    {
        $now = now()->timestamp;
        $last = Cache::get(self::KEY);

        // First run after enabling: nothing to compare yet, start the clock.
        if ($last === null && Cache::add(self::SINCE_KEY, $now, now()->addDays(30))) {
            return 'waiting';
        }

        $reference = (int) ($last ?? Cache::get(self::SINCE_KEY, $now));
        $age = $now - $reference;
        $staleAfter = max(1, (int) config('queue_monitor.stale_minutes', 5)) * 60;
        $downSince = Cache::get(self::DOWN_KEY);

        if ($age > $staleAfter) {
            if ($downSince === null) {
                Cache::forever(self::DOWN_KEY, $now);
            }

            $this->alerts->send(
                '🔴 <b>Queue worker looks down</b>' . "\n"
                . ($last === null ? 'No heartbeat yet' : 'Last heartbeat ' . $this->ago($age) . ' ago') . "\n"
                . 'Jobs waiting: ' . $this->pending() . "\n"
                . 'Widgets and other queued work wait until it is back',
                'queue_down',
                max(1, (int) config('queue_monitor.repeat_minutes', 60)) * 60,
            );

            return 'down';
        }

        if ($downSince !== null) {
            Cache::forget(self::DOWN_KEY);
            Cache::forget('admin_alert:queue_down');
            $this->alerts->send('✅ <b>Queue worker recovered</b>' . "\n" . 'It was down for about ' . $this->ago($now - (int) $downSince + $staleAfter));

            return 'recovered';
        }

        return 'ok';
    }

    private function ago(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        return $minutes < 60 ? $minutes . ' min' : intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    }

    private function pending(): string
    {
        try {
            return config('queue.default') === 'database' ? (string) DB::table('jobs')->count() : 'n/a';
        } catch (Throwable) {
            return 'n/a';
        }
    }
}
