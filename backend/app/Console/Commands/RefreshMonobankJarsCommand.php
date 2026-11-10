<?php

namespace App\Console\Commands;

use App\Jobs\RefreshMonobankJarsJob;
use App\Models\MonobankAccount;
use Illuminate\Console\Command;

class RefreshMonobankJarsCommand extends Command
{
    protected $signature = 'monobank:refresh-jars {--pending : Only connections flagged by a webhook}';

    protected $description = 'Refresh monobank jar balances used by linked savings goals (client-info, respects the 60s rate limit)';

    public function handle(): int
    {
        $ids = MonobankAccount::query()
            ->where('type', 'jar')
            ->distinct()
            ->pluck('monobank_connection_id');

        foreach ($ids as $id) {
            $key = 'monobank:jar-refresh:'.$id;

            if ($this->option('pending') && ! \Illuminate\Support\Facades\Cache::has($key)) {
                continue;
            }

            \Illuminate\Support\Facades\Cache::forget($key);
            RefreshMonobankJarsJob::dispatchSync((int) $id);
        }

        return self::SUCCESS;
    }
}
