<?php

namespace App\Channels\Telegram;

use App\Channels\Telegram\Widgets\WidgetAvailability;
use App\Support\TelegramHtml;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramClient
{
    public const RICH_LIMIT = 32000;

    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://api.telegram.org/bot' . config('services.telegram.bot_token') . '/';
    }

    /**
     * @param null|array<string, mixed> $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): void {
        $this->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
        ]);
    }

    /**
     * sendMessage that reports back: the decoded response, or ok:false on a network error.
     * $replyTo makes it a reply (still sent if that message is gone).
     *
     * @param null|array<string, mixed> $replyMarkup
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
            $response = Http::timeout(20)->post($this->baseUrl . 'sendMessage', $this->fields([
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
     * @param null|array<string, mixed> $replyMarkup
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
     * Bot API 10.1 rich message (h3/h4, tables, footer). Returns the decoded API payload.
     *
     * @param null|array<string, mixed> $replyMarkup
     * @return array<string, mixed>
     */
    public function sendRichMessageResult(int|string $chatId, string $html, ?array $replyMarkup = null, ?int $replyTo = null): array
    {
        return $this->postResult('sendRichMessage', [
            'chat_id' => $chatId,
            'rich_message' => ['html' => mb_substr($html, 0, self::RICH_LIMIT)],
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
            'reply_parameters' => $replyTo !== null
                ? json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true])
                : null,
        ]);
    }

    /**
     * The middle step of widget -> rich -> classic HTML: a rich message, else $fallbackHtml as a
     * classic HTML message (the same buttons either way). Returns the API payload of the send
     * that went out.
     *
     * @param null|array<string, mixed> $replyMarkup
     * @return array<string, mixed>
     */
    public function sendRichOrHtml(int|string $chatId, string $richHtml, string $fallbackHtml, ?array $replyMarkup = null, ?int $replyTo = null): array
    {
        if ($richHtml !== '' && !WidgetAvailability::richBlocked()) {
            $result = $this->sendRichMessageResult($chatId, $richHtml, $replyMarkup, $replyTo);

            if (($result['ok'] ?? false) === true) {
                return $result;
            }

            WidgetAvailability::noteRichFailure($result);
            Log::warning('TelegramClient: sendRichMessage failed, sending classic HTML', [
                'error_code' => $result['error_code'] ?? null,
                'description' => mb_substr((string) ($result['description'] ?? ''), 0, 200),
            ]);
        }

        $result = $this->sendMessageResult($chatId, $fallbackHtml, $replyMarkup, 'HTML', $replyTo);

        if (($result['ok'] ?? false) !== true && ($result['error_code'] ?? null) === 400) {
            // Last resort: the same text without markup.
            $result = $this->sendMessageResult($chatId, mb_substr(TelegramHtml::toPlain($fallbackHtml), 0, TelegramHtml::LIMIT), $replyMarkup, null, $replyTo);
        }

        return $result;
    }

    /**
     * Edit a message as rich, else as classic HTML. "message is not modified" counts as done.
     *
     * @param null|array<string, mixed> $replyMarkup
     * @return array<string, mixed>
     */
    public function editRichOrHtml(int|string $chatId, int $messageId, string $richHtml, string $fallbackHtml, ?array $replyMarkup = null): array
    {
        $markup = $replyMarkup !== null ? json_encode($replyMarkup) : null;

        if ($richHtml !== '' && !WidgetAvailability::richBlocked()) {
            $result = $this->postResult('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'rich_message' => ['html' => mb_substr($richHtml, 0, self::RICH_LIMIT)],
                'reply_markup' => $markup,
            ]);

            if (($result['ok'] ?? false) === true || self::notModified($result)) {
                return $result;
            }

            WidgetAvailability::noteRichFailure($result);
        }

        return $this->postResult('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $fallbackHtml,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function notModified(array $result): bool
    {
        return str_contains((string) ($result['description'] ?? ''), 'message is not modified');
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

    /**
     * "⏳ Analyzing receipt…" as a live draft plus a chat action, for work that takes a while
     * (receipts, voice). Private chats with drafts on only; false = not shown, so the caller can
     * send a status message instead. The draft goes away with the next real message.
     */
    public function statusDraft(int|string $chatId, string $label, string $action = 'typing'): bool
    {
        if (!config('telegram.stream_drafts') || !is_numeric($chatId) || (int) $chatId <= 0) {
            return false;
        }

        $this->sendChatAction($chatId, $action);

        return $this->sendMessageDraft($chatId, random_int(1, 2_000_000_000), '⏳ ' . $label . '…');
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        Http::post($this->baseUrl . 'answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    /**
     * @param null|array<string, mixed> $replyMarkup
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
     * @param null|array<string, mixed> $replyMarkup
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
    ): void {
        $this->post('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
        ]);
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
     * Bot API 10.4: send an HTML widget. Returns the decoded API payload.
     *
     * @return array<string, mixed>
     */
    public function sendWidget(
        int|string $chatId,
        string $html,
        ?string $chatData = null,
        ?string $caption = null,
        bool $allowQueries = false,
        ?int $height = null,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): array {
        try {
            $response = Http::post($this->baseUrl . 'sendWidget', array_filter([
                'chat_id' => $chatId,
                'html' => $html,
                'chat_data' => $chatData,
                'caption' => $caption,
                'parse_mode' => $parseMode,
                'allow_queries' => $allowQueries ?: null,
                'height' => $height,
                'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : null,
            ], static fn ($value) => $value !== null && $value !== ''));
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => false, 'error_code' => $response->status()];
    }

    public function pinChatMessage(int|string $chatId, int $messageId): bool
    {
        return $this->postOk('pinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId, 'disable_notification' => true]);
    }

    public function unpinChatMessage(int|string $chatId, int $messageId): bool
    {
        return $this->postOk('unpinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        return $this->postOk('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function editMessageWidgetData(int|string $chatId, int $messageId, ?string $chatData = null, ?string $publicData = null): array
    {
        try {
            $payload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ];

            if ($chatData !== null) {
                $payload['chat_data'] = $chatData;
            }

            if ($publicData !== null) {
                $payload['public_data'] = $publicData;
            }
            $response = Http::post($this->baseUrl . 'editMessageWidgetData', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => false, 'error_code' => $response->status()];
    }

    public function answerWidgetQuery(string $widgetQueryId, ?string $data = null): void
    {
        try {
            Http::timeout(10)->post($this->baseUrl . 'answerWidgetQuery', array_filter([
                'widget_query_id' => $widgetQueryId,
                'data' => $data,
            ], static fn ($value) => $value !== null && $value !== ''));
        } catch (\Throwable $e) {
            Log::warning('TelegramClient: answerWidgetQuery failed', [
                'description' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
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

    /**
     * Drop only nulls. array_filter() without a callback also drops the text "0".
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function fields(array $payload): array
    {
        return array_filter($payload, static fn ($value) => $value !== null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $method, array $payload): void
    {
        try {
            $response = Http::timeout(20)->post($this->baseUrl . $method, $this->fields($payload));
        } catch (\Throwable $e) {
            Log::warning('TelegramClient: ' . $method . ' failed', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $json = $response->json();

        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            Log::warning('TelegramClient: ' . $method . ' rejected', [
                'status' => $response->status(),
                'description' => mb_substr((string) (is_array($json) ? ($json['description'] ?? '') : ''), 0, 200),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postResult(string $method, array $payload): array
    {
        try {
            $response = Http::timeout(20)->post($this->baseUrl . $method, $this->fields($payload));
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => false, 'error_code' => $response->status()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postOk(string $method, array $payload): bool
    {
        try {
            return (bool) (Http::post($this->baseUrl . $method, $payload)->json('ok') ?? false);
        } catch (\Throwable) {
            return false;
        }
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
}
