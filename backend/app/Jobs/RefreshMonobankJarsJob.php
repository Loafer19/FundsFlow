<?php

namespace App\Jobs;

use App\Actions\Monobank\RefreshMonobankJarsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RefreshMonobankJarsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 90;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(RefreshMonobankJarsAction $refresh): void
    {
        $refresh->execute($this->connectionId);
    }
}
