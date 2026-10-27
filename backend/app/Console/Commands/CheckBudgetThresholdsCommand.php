<?php

namespace App\Console\Commands;

use App\Actions\Budgets\NotifyBudgetThresholdsAction;
use Illuminate\Console\Command;

class CheckBudgetThresholdsCommand extends Command
{
    protected $signature = 'budgets:check-thresholds';

    protected $description = 'Send a Telegram alert for budget limits that crossed 80%/100% of their current period, and once when a savings goal is reached';

    public function handle(NotifyBudgetThresholdsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
