<?php

namespace App\Console\Commands;

use App\Actions\Notifications\SendWeeklyDigestAction;
use Illuminate\Console\Command;

class SendWeeklyDigestCommand extends Command
{
    protected $signature = 'digest:weekly';

    protected $description = 'Send the weekly activity report to users with a linked, unmuted Telegram account';

    public function handle(SendWeeklyDigestAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
