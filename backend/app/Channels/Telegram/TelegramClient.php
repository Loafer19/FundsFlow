<?php

namespace App\Channels\Telegram;

use Illuminate\Support\Facades\Http;

class TelegramClient
{
    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://api.telegram.org/bot' . config('services.telegram.bot_token') . '/';
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        Http::post($this->baseUrl . 'sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
        ]));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        Http::post($this->baseUrl . 'answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        Http::post($this->baseUrl . 'editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []]),
        ]);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        Http::post($this->baseUrl . 'editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, string $secretToken): array
    {
        return Http::post($this->baseUrl . 'setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
        ])->json();
    }

    /**
     * @param array<int, array{command: string, description: string}> $commands
     * @return array<string, mixed>
     */
    public function setMyCommands(array $commands): array
    {
        return Http::post($this->baseUrl . 'setMyCommands', [
            'commands' => json_encode($commands),
        ])->json();
    }
}
