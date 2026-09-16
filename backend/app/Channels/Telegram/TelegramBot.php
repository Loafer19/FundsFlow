<?php

namespace App\Channels\Telegram;

use App\Channels\Telegram\Handlers\CallbackHandler;
use App\Channels\Telegram\Handlers\MediaHandler;
use App\Channels\Telegram\Handlers\MessageHandler;

class TelegramBot
{
    public function __construct(
        private readonly CallbackHandler $callbackHandler,
        private readonly MessageHandler $messageHandler,
        private readonly MediaHandler $mediaHandler,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callbackHandler->handleCallbackQuery($update['callback_query']);

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

        if (isset($message['text'])) {
            $this->messageHandler->handleMessage($message);
        }
    }
}
