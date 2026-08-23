<?php

use App\Console\Commands\CheckBudgetThresholdsCommand;
use App\Console\Commands\GenerateRecurringTransactionsCommand;
use App\Console\Commands\SendWeeklyDigestCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CheckBudgetThresholdsCommand::class)->hourly();
Schedule::command(SendWeeklyDigestCommand::class)->weeklyOn(1, '09:00');
Schedule::command(GenerateRecurringTransactionsCommand::class)->dailyAt('06:00');
