<?php

namespace App\Console\Commands;

use App\Actions\Agent\Web\WebAgentUploads;
use Illuminate\Console\Command;

class PruneAgentUploadsCommand extends Command
{
    protected $signature = 'agent:prune-uploads {--minutes= : Delete photos older than this (default: web chat session TTL + 60)}';

    protected $description = 'Delete leftover receipt photos sent to the web assistant (unconfirmed or expired)';

    public function handle(WebAgentUploads $uploads): int
    {
        $minutes = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : max(1, (int) config('agent.web_session_ttl_minutes')) + 60;

        $deleted = $uploads->prune($minutes);
        $this->info("Deleted {$deleted} leftover assistant photo(s).");

        return self::SUCCESS;
    }
}
