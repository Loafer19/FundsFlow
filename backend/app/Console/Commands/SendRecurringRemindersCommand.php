<?php

namespace App\Console\Commands;

use App\Channels\Telegram\RecurringReminders;
use Illuminate\Console\Command;

class SendRecurringRemindersCommand extends Command
{
    protected $signature = 'recurring-transactions:remind';

    protected $description = 'Telegram reminder the day before a recurring payment (Skip / Pause)';

    public function handle(RecurringReminders $reminders): int
    {
        $this->info('Reminders sent: ' . $reminders->sendDue());

        return self::SUCCESS;
    }
}
