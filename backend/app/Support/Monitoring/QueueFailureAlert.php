<?php

namespace App\Support\Monitoring;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;

/**
 * Queue::failing → a short Telegram note to the admins. The same job + exception class is
 * reported at most once per throttle window; the full trace stays in failed_jobs (/admin).
 */
class QueueFailureAlert
{
    private const MESSAGE_MAX = 300;

    public function __construct(
        private readonly AdminAlerts $alerts,
    ) {}

    public function handle(JobFailed $event): void
    {
        $job = $event->job->resolveName();
        $exception = $event->exception;
        $minutes = max(1, (int) config('queue_monitor.failure_throttle_minutes', 10));

        $text = '⚠️ <b>Queue job failed</b>' . "\n"
            . AdminAlerts::escape(class_basename($job)) . "\n"
            . '<code>' . AdminAlerts::escape(class_basename($exception)) . '</code>: '
            . AdminAlerts::escape(Str::limit(trim($exception->getMessage()), self::MESSAGE_MAX)) . "\n"
            . 'Queue: ' . AdminAlerts::escape((string) $event->job->getQueue())
            . ' · ' . AdminAlerts::escape($event->connectionName) . "\n"
            . 'Retry or delete it in /admin → Failed jobs. Same failures are muted for ' . $minutes . ' min';

        $this->alerts->send($text, 'job_failed:' . md5($job . '|' . $exception::class), $minutes * 60);
    }
}
