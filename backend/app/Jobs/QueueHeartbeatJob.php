<?php

namespace App\Jobs;

use App\Support\Monitoring\QueueHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched every minute; proves the worker takes jobs off the queue. Unique, so a stopped
 * worker leaves one waiting heartbeat instead of one per minute.
 */
class QueueHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        QueueHeartbeat::beat();
    }
}
