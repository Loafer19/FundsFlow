<?php

use App\Console\Commands\CheckBudgetThresholdsCommand;
use App\Console\Commands\GenerateRecurringTransactionsCommand;
use App\Console\Commands\SendWeeklyDigestCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CheckBudgetThresholdsCommand::class)->hourly();
Schedule::command(SendWeeklyDigestCommand::class)->weeklyOn(1, '09:00');
Schedule::command(GenerateRecurringTransactionsCommand::class)->dailyAt('06:00');
// Leftover one-time report:print tokens (normally revoked within seconds) and expired logins.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
