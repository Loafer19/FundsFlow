<?php

namespace App\Console\Commands;

use App\Channels\Telegram\TelegramClient;
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

        return self::SUCCESS;
    }
}
