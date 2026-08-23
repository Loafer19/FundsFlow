<?php

namespace App\Console\Commands;

use App\Actions\Budgets\NotifyBudgetThresholdsAction;
use Illuminate\Console\Command;

class CheckBudgetThresholdsCommand extends Command
{
    protected $signature = 'budgets:check-thresholds';

    protected $description = 'Send a Telegram alert for budgets that crossed 80%/100% of their current period';

    public function handle(NotifyBudgetThresholdsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
