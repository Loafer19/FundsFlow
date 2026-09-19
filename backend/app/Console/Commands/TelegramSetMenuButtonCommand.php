<?php

namespace App\Console\Commands;

use App\Channels\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetMenuButtonCommand extends Command
{
    protected $signature = 'telegram:menu-button {url? : Mini App URL (defaults to FRONTEND_URL)}';

    protected $description = 'Register the Telegram chat menu button that opens the FundsFlow Mini App';

    public function handle(TelegramClient $client): int
    {
        $url = $this->argument('url') ?? rtrim((string) config('app.frontend_url'), '/');

        if ($url === '') {
            $this->error('FRONTEND_URL is not set.');

            return self::FAILURE;
        }

        if (!str_starts_with($url, 'https://')) {
            $this->warn("Telegram Mini Apps require an HTTPS public URL. Got: {$url}");
        }

        $response = $client->setChatMenuButton(null, [
            'type' => 'web_app',
            'text' => 'Open FundsFlow',
            'web_app' => [
                'url' => $url,
            ],
        ]);

        $this->line(json_encode($response, JSON_PRETTY_PRINT));

        if (!($response['ok'] ?? false)) {
            $this->error('Failed to set chat menu button.');

            return self::FAILURE;
        }

        $this->info("Menu button set to open {$url}");

        return self::SUCCESS;
    }
}
