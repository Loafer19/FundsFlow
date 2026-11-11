<?php

namespace App\Console\Commands;

use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use Illuminate\Console\Command;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook-set {url? : Override the webhook URL (defaults to APP_URL/api/telegram/webhook)}';

    protected $description = 'Register the Telegram bot webhook with Telegram';

    public function handle(TelegramClient $client): int
    {
        $url = $this->argument('url') ?? rtrim((string) config('app.url'), '/') . '/api/telegram/webhook';
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not set.');

            return self::FAILURE;
        }

        $response = $client->setWebhook($url, $secret);

        $this->line(json_encode($response, JSON_PRETTY_PRINT));

        if (!($response['ok'] ?? false)) {
            $this->error('Failed to set webhook.');

            return self::FAILURE;
        }

        $this->info("Webhook set to {$url}");

        $client->setMyCommands(TelegramSupport::BOT_COMMANDS);

        $this->info('Bot command menu registered.');

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if ($frontend !== '') {
            $menuResponse = $client->setChatMenuButton(null, [
                'type' => 'web_app',
                'text' => 'Web UI',
                'web_app' => ['url' => $frontend],
            ]);

            if ($menuResponse['ok'] ?? false) {
                $this->info("Mini App menu button set to {$frontend}");
            } else {
                $this->warn('Webhook OK, but menu button failed: ' . json_encode($menuResponse));
            }
        }

        return self::SUCCESS;
    }
}
