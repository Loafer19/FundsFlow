<?php

namespace App\Jobs;

use App\Channels\Telegram\TelegramBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One attempt only: a retry would re-run the AI agent / vision / STT and spend quota twice,
     * and the user would get duplicate replies.
     */
    public int $tries = 1;

    /**
     * Above the worst case of the slowest path (agent up to 6×60 s plus tool calls, vision 45 s,
     * STT 60 s, album debounce).
     */
    public int $timeout = 600;

    /**
     * @param array<string, mixed> $update
     */
    public function __construct(public readonly array $update) {}

    public function handle(TelegramBot $bot): void
    {
        // When run after the response (no queue worker), PHP's web max_execution_time
        // would otherwise kill long agent runs.
        @set_time_limit($this->timeout);
        ignore_user_abort(true);

        try {
            $bot->handle($this->update);
        } catch (Throwable $e) {
            // Swallow so a failed update is reported once and never retried (see $tries).
            report($e);
        }
    }
}
