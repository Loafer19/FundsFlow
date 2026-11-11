<?php

// Queue worker monitoring: a heartbeat job every minute and a check every 5 minutes that
// tells the admins in Telegram when the worker stops (and when it is back). Failed jobs
// are reported regardless of this switch.

return [
    // On by default when a worker is expected (TELEGRAM_WIDGETS_QUEUE=true). Without a worker,
    // keep it off: the heartbeat job would wait in the queue and the check would alert.
    'enabled' => (bool) env('QUEUE_MONITOR', env('TELEGRAM_WIDGETS_QUEUE', false)),

    // The worker counts as down when the last heartbeat is older than this.
    'stale_minutes' => (int) env('QUEUE_HEARTBEAT_STALE_MINUTES', 5),

    // While it stays down, repeat the alert this often.
    'repeat_minutes' => (int) env('QUEUE_DOWN_REPEAT_MINUTES', 60),

    // Identical failed-job alerts (same job and exception class) at most this often.
    'failure_throttle_minutes' => (int) env('QUEUE_FAILURE_ALERT_THROTTLE_MINUTES', 10),
];
