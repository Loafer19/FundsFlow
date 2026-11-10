<?php

namespace App\Jobs;

use App\Channels\Telegram\Widgets\TelegramWidgetSync;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTelegramWidgetsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 10;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(TelegramWidgetSync $sync): void
    {
        $user = User::query()->find($this->userId);

        if (!$user) {
            return;
        }

        $sync->refresh($user);
    }
}
