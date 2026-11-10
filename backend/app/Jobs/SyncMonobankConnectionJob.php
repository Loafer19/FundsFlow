<?php

namespace App\Jobs;

use App\Actions\Monobank\SyncMonobankConnectionAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMonobankConnectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public readonly int $connectionId,
        public readonly bool $full = false,
    ) {}

    public function handle(SyncMonobankConnectionAction $sync): void
    {
        @set_time_limit($this->timeout);
        $sync->execute($this->connectionId, $this->full);
    }
}
