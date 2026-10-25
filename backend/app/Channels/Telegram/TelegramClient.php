<?php

namespace App\Channels\Telegram;

use App\Support\TelegramHtml;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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


    /**
     * sendMessage that reports back: the decoded response, or ok:false on a network error.
     * $replyTo makes it a reply (still sent if that message is gone).
     *
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>
     */
    public function sendMessageResult(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
        ?int $replyTo = null,
    ): array {
        try {
            $response = Http::post($this->baseUrl . 'sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
                'reply_parameters' => $replyTo !== null
                    ? json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true])
                    : null,
            ]));
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => false, 'error_code' => $response->status()];
    }

    /**
     * Send the assistant's light-Markdown text as Telegram HTML, split at paragraph / line
     * boundaries into messages under 4096 characters. Only the first part replies to
     * $replyTo, only the last carries $replyMarkup. A part Telegram can't parse
     * (400 "can't parse entities") is resent as plain text.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    public function sendFormatted(int|string $chatId, string $markdown, ?array $replyMarkup = null, ?int $replyTo = null): void
    {
        $parts = TelegramHtml::split($markdown);
        $last = count($parts) - 1;

        foreach ($parts as $i => $html) {
            $markup = $i === $last ? $replyMarkup : null;
            $reply = $i === 0 ? $replyTo : null;
            $result = $this->sendMessageResult($chatId, $html, $markup, 'HTML', $reply);
            if (($result['ok'] ?? false) === true) {
                continue;
            }

            Log::warning('TelegramClient: formatted send failed, retrying as plain text', [
                'error_code' => $result['error_code'] ?? null,
                'description' => mb_substr((string) ($result['description'] ?? ''), 0, 200),
            ]);
            if (($result['error_code'] ?? null) === 400) {
                $this->sendMessageResult($chatId, mb_substr(TelegramHtml::toPlain($html), 0, TelegramHtml::LIMIT), $markup, null, $reply);
            }
        }
    }

    /**
     * "typing…" (or another action) in the chat header for ~5 seconds or until the next message.
     */
    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        try {
            Http::timeout(5)->post($this->baseUrl . 'sendChatAction', [
                'chat_id' => $chatId,
                'action' => $action,
            ]);
        } catch (\Throwable) {
            // Cosmetic only.
        }
    }

    /**
     * Bot API 9.5 draft streaming (private chats): shows $text as a message being typed.
     * The same non-zero $draftId updates it; the real message still has to be sent after.
     */
    public function sendMessageDraft(int|string $chatId, int $draftId, string $text): bool
    {
        try {
            return (bool) Http::timeout(5)->post($this->baseUrl . 'sendMessageDraft', [
                'chat_id' => $chatId,
                'draft_id' => $draftId,
                'text' => $text,
            ])->json('ok');
        } catch (\Throwable) {
            return false;
        }
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
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): void {
        Http::post($this->baseUrl . 'editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function sendPhoto(
        int|string $chatId,
        string $contents,
        string $filename,
        ?string $caption = null,
    ): array {
        return $this->sendMultipart('sendPhoto', 'photo', $chatId, $contents, $filename, $caption);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(
        int|string $chatId,
        string $contents,
        string $filename,
        ?string $caption = null,
    ): array {
        return $this->sendMultipart('sendDocument', 'document', $chatId, $contents, $filename, $caption);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMultipart(
        string $method,
        string $field,
        int|string $chatId,
        string $contents,
        string $filename,
        ?string $caption,
    ): array {
        // Always raw bytes. Never treat the argument as a path: callers pass
        // user-uploaded contents, and a file whose bytes spell "/app/.env"
        // would otherwise be read from disk and sent to the user.
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
