<?php

namespace App\Channels\Telegram;

use App\Channels\Telegram\Handlers\CallbackHandler;
use App\Channels\Telegram\Handlers\MediaHandler;
use App\Channels\Telegram\Handlers\MessageHandler;
use App\Channels\Telegram\Handlers\WidgetQueryHandler;
use App\Support\UserActivity;

class TelegramBot
{
    public function __construct(
        private readonly CallbackHandler $callbackHandler,
        private readonly MessageHandler $messageHandler,
        private readonly MediaHandler $mediaHandler,
        private readonly WidgetQueryHandler $widgetQueryHandler,
        private readonly TelegramSupport $support,
        private readonly TelegramClient $client,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function handle(array $update): void
    {
        $this->handleUpdate($update);
    }

    /**
     * @param array<string, mixed> $update
     */
    private function handleUpdate(array $update): void
    {
        if ($this->rejectBlocked($update)) {
            return;
        }

        if (isset($update['callback_query'])) {
            $this->callbackHandler->handleCallbackQuery($update['callback_query']);

            return;
        }

        if (isset($update['widget_query']) && is_array($update['widget_query'])) {
            $this->widgetQueryHandler->handle($update['widget_query']);

            return;
        }

        if (!isset($update['message']) || !is_array($update['message'])) {
            return;
        }

        $message = $update['message'];

        if (isset($message['photo']) || isset($message['document'])) {
            $this->mediaHandler->handleMediaMessage($message);

            return;
        }

        // Voice notes (and optional audio files) → STT → same text path as typed messages.
        // video_note skipped for MVP.
        if (isset($message['voice']) || isset($message['audio'])) {
            $this->messageHandler->handleVoiceMessage($message);

            return;
        }

        if (isset($message['text'])) {
            $this->messageHandler->handleMessage($message);
        }
    }

    /**
     * Accounts blocked in /admin get one short reply and nothing else runs.
     *
     * @param array<string, mixed> $update
     */
    private function rejectBlocked(array $update): bool
    {
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        $widgetQuery = is_array($update['widget_query'] ?? null) ? $update['widget_query'] : null;
        $chatId = $callback
            ? ($callback['message']['chat']['id'] ?? null)
            : ($widgetQuery['chat']['id'] ?? $widgetQuery['from']['id'] ?? $update['message']['chat']['id'] ?? null);

        $user = $chatId === null ? null : $this->support->resolveUser($chatId);

        if (!$user?->isBlocked()) {
            if ($user) {
                // Telegram chats don't use Sanctum tokens; stamp users.last_active_at for /admin.
                UserActivity::touch($user);
            }

            return false;
        }

        if ($callback && isset($callback['id'])) {
            $this->client->answerCallbackQuery((string) $callback['id'], 'Account blocked');
        } elseif ($widgetQuery && isset($widgetQuery['id'])) {
            $this->client->answerWidgetQuery((string) $widgetQuery['id'], '{"ok":0,"e":"Account blocked"}');
        } else {
            $this->client->sendMessage($chatId, 'Account blocked.');
        }

        return true;
    }
}
