<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeatJob;
use App\Support\Monitoring\QueueHeartbeat;
use Illuminate\Console\Command;

class QueueHeartbeatCommand extends Command
{
    protected $signature = 'queue:heartbeat {--check : Check the last heartbeat and alert the admins instead of sending one}';

    protected $description = 'Queue a heartbeat job for the worker, or check that the worker is alive';

    public function handle(QueueHeartbeat $heartbeat): int
    {
        if ($this->option('check')) {
            $this->info('Queue worker: ' . $heartbeat->check());

            return self::SUCCESS;
        }

        QueueHeartbeatJob::dispatch();
        $this->info('Heartbeat queued');

        return self::SUCCESS;
    }
}
