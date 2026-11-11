<?php

namespace App\Console\Commands;

use App\Channels\Telegram\PinnedMonthSummary;
use Illuminate\Console\Command;

class TelegramPinnedMonthCommand extends Command
{
    protected $signature = 'telegram:pinned-month';

    protected $description = 'Pin a fresh month summary in chats that turned it on, once their month changes';

    public function handle(PinnedMonthSummary $pinned): int
    {
        $this->info('Re-pinned: ' . $pinned->rollover());

        return self::SUCCESS;
    }
}
