<?php

namespace App\Console\Commands;

use App\Actions\RecurringTransactions\GenerateRecurringTransactionsAction;
use Illuminate\Console\Command;

class GenerateRecurringTransactionsCommand extends Command
{
    protected $signature = 'recurring-transactions:generate';

    protected $description = 'Materialize due recurring transactions into real transactions';

    public function handle(GenerateRecurringTransactionsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
