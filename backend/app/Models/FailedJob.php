<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Laravel's failed_jobs table, read in /admin → Failed jobs (retry and delete go through
 * queue:retry / queue:forget so the queue stays the source of truth).
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property Carbon $failed_at
 */
class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';

    protected $guarded = [];

    public function jobName(): string
    {
        $payload = json_decode($this->payload, true);

        return (string) ($payload['displayName'] ?? 'Unknown job');
    }

    /** First line of the stored exception, e.g. "RuntimeException: Telegram is down". */
    public function exceptionSummary(): string
    {
        return strtok($this->exception, "\n") ?: '';
    }

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }
}
