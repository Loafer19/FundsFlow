<?php

use App\Console\Commands\CheckBudgetThresholdsCommand;
use App\Console\Commands\RefreshMonobankJarsCommand;
use App\Console\Commands\GenerateRecurringTransactionsCommand;
use App\Console\Commands\PruneAgentUploadsCommand;
use App\Console\Commands\SendRecurringRemindersCommand;
use App\Console\Commands\SendWeeklyDigestCommand;
use App\Console\Commands\TelegramPinnedMonthCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CheckBudgetThresholdsCommand::class)->hourly();
Schedule::command(RefreshMonobankJarsCommand::class)->hourly();
Schedule::command(RefreshMonobankJarsCommand::class, ['--pending'])->everyFiveMinutes();
Schedule::command(SendWeeklyDigestCommand::class)->weeklyOn(1, '09:00');
Schedule::command(GenerateRecurringTransactionsCommand::class)->dailyAt('06:00');
// Day-before reminders go out from each user's local 18:00, so this runs every hour.
Schedule::command(SendRecurringRemindersCommand::class)->hourlyAt(5);
// Pinned month summary: a fresh pinned widget once a chat's month changes (local time).
Schedule::command(TelegramPinnedMonthCommand::class)->hourlyAt(2);
// Leftover one-time report:print tokens (normally revoked within seconds) and expired logins.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
// Receipt photos sent to the web assistant whose Confirm card was never resolved.
Schedule::command(PruneAgentUploadsCommand::class)->hourly();
