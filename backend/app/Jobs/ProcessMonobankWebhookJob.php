<?php

namespace App\Jobs;

use App\Actions\Monobank\ProcessMonobankWebhookAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessMonobankWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly array $payload,
    ) {}

    public function handle(ProcessMonobankWebhookAction $process): void
    {
        $process->execute($this->connectionId, $this->payload);
    }
}
