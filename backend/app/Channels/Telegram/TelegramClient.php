<?php

namespace App\Channels\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

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
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): void {
        Http::post($this->baseUrl . 'sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
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
    public function sendPhoto(
        int|string $chatId,
        string $contentsOrPath,
        string $filename,
        ?string $caption = null,
    ): array {
        return $this->sendMultipart('sendPhoto', 'photo', $chatId, $contentsOrPath, $filename, $caption);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(
        int|string $chatId,
        string $contentsOrPath,
        string $filename,
        ?string $caption = null,
    ): array {
        return $this->sendMultipart('sendDocument', 'document', $chatId, $contentsOrPath, $filename, $caption);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMultipart(
        string $method,
        string $field,
        int|string $chatId,
        string $contentsOrPath,
        string $filename,
        ?string $caption,
    ): array {
        $contents = $this->resolveFileContents($contentsOrPath);

        $response = Http::attach($field, $contents, $filename)
            ->post($this->baseUrl . $method, array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
            ], static fn ($value) => $value !== null && $value !== ''));

        $json = $response->json() ?? [];

        if (!($json['ok'] ?? false)) {
            $description = is_string($json['description'] ?? null)
                ? $json['description']
                : 'Telegram send failed';

            throw new RuntimeException($description);
        }

        return $json;
    }

    private function resolveFileContents(string $contentsOrPath): string
    {
        if (
            $contentsOrPath !== ''
            && !str_contains($contentsOrPath, "\0")
            && is_file($contentsOrPath)
            && is_readable($contentsOrPath)
        ) {
            $contents = file_get_contents($contentsOrPath);

            if ($contents === false) {
                throw new RuntimeException('Could not read file for Telegram upload.');
            }

            return $contents;
        }

        return $contentsOrPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        return Http::get($this->baseUrl . 'getFile', [
            'file_id' => $fileId,
        ])->throw()->json();
    }

    public function downloadFile(string $filePath): string
    {
        $token = config('services.telegram.bot_token');
        $response = Http::get('https://api.telegram.org/file/bot' . $token . '/' . ltrim($filePath, '/'))
            ->throw();

        return $response->body();
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

    /**
     * Set the chat menu button (omit chatId to set the default for all private chats).
     *
     * @param array<string, mixed> $menuButton
     * @return array<string, mixed>
     */
    public function setChatMenuButton(?int $chatId, array $menuButton): array
    {
        return Http::post($this->baseUrl . 'setChatMenuButton', array_filter([
            'chat_id' => $chatId,
            'menu_button' => json_encode($menuButton),
        ], static fn ($value) => $value !== null))->json();
    }
}
