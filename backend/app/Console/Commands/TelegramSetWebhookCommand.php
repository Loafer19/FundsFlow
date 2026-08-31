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

        $client->setMyCommands([
            ['command' => 'month', 'description' => "This month's income, expenses, net, and top tags"],
            ['command' => 'analytics', 'description' => 'Same as /month'],
            ['command' => 'recent', 'description' => 'Last 10 transactions'],
            ['command' => 'tags', 'description' => 'List your tags'],
            ['command' => 'newtag', 'description' => 'Create a tag: /newtag 🍕 Fast Food > Food'],
            ['command' => 'budgets', 'description' => 'Active budgets: spent vs limit'],
            ['command' => 'recurring', 'description' => 'List recurring transaction rules'],
            ['command' => 'website', 'description' => 'Get a one-time code to log in on the website'],
            ['command' => 'mute', 'description' => 'Mute budget alerts, weekly digest, and recurring notifications'],
            ['command' => 'unmute', 'description' => 'Turn budget alerts, weekly digest, and recurring notifications back on'],
            ['command' => 'unlink', 'description' => 'Unlink Telegram; account stays on the website'],
            ['command' => 'help', 'description' => 'List commands and quick-add format'],
        ]);

        $this->info('Bot command menu registered.');

        return self::SUCCESS;
    }
}
