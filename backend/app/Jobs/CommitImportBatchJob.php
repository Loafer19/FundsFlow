<?php

namespace App\Jobs;

use App\Actions\Imports\CommitImportBatchAction;
use App\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CommitImportBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(public readonly int $batchId) {}

    public function handle(CommitImportBatchAction $commit): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if (!$batch || $batch->status === ImportBatch::STATUS_COMMITTED) {
            return;
        }
        $commit->execute($batch->user, $batch);
    }
}
