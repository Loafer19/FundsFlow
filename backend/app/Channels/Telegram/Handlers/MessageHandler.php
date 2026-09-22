<?php

namespace App\Channels\Telegram\Handlers;

use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class MessageHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly AuthHandler $authHandler,
        private readonly MenuHandler $menuHandler,
        private readonly QuickAddHandler $quickAddHandler,
        private readonly MediaHandler $mediaHandler,
    ) {}

    /**
     * @param array<string, mixed> $message
     */
    public function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text']);

        if (str_starts_with($text, '/start')) {
            $this->authHandler->handleStart($message);

            return;
        }

        if ($text === '/help') {
            $this->support->sendHelp($chatId);

            return;
        }

        if ($text === '/app') {
            $this->support->sendMiniAppHint($chatId);

            return;
        }

        $user = $this->support->resolveUser($chatId);

        if (!$user) {
            if ($text === '/unlink') {
                $this->client->sendMessage($chatId, "This chat isn't linked to a FundsFlow account");

                return;
            }

            $this->client->sendMessage($chatId, "Your account isn't linked yet. Send /start to get started");

            return;
        }

        match (true) {
            $text === '/month' || $text === '/analytics' || $text === TelegramSupport::MENU_MONTH => $this->menuHandler->sendMonthSummary($user, $chatId),
            $text === '/tags' || $text === TelegramSupport::MENU_TAGS => $this->menuHandler->sendTags($user, $chatId),
            $text === '/recent' || $text === TelegramSupport::MENU_RECENT => $this->menuHandler->sendRecent($user, $chatId),
            $text === '/budgets' || $text === TelegramSupport::MENU_BUDGETS => $this->menuHandler->sendBudgets($user, $chatId),
            $text === '/recurring' || $text === TelegramSupport::MENU_RECURRING => $this->menuHandler->sendRecurring($user, $chatId),
            $text === TelegramSupport::MENU_WEB => $this->support->sendMiniAppHint($chatId),
            $text === '/website' => $this->authHandler->sendWebsiteLoginCode($user, $chatId),
            $text === '/mute' => $this->authHandler->handleMute($chatId, true),
            $text === '/unmute' => $this->authHandler->handleMute($chatId, false),
            $text === '/unlink' => $this->authHandler->handleUnlink($chatId),
            str_starts_with($text, '/newtag') => $this->menuHandler->handleNewTag($user, $chatId, $text),
            default => $this->handleDefaultText($user, $chatId, $text),
        };
    }

    private function handleDefaultText(User $user, int|string $chatId, string $text): void
    {
        if (Cache::has("telegram_pending_media:{$chatId}") || Cache::has("telegram_pending_media_await_text:{$chatId}")) {
            $this->mediaHandler->completePendingMedia($user, $chatId, $text);

            return;
        }

        if (Cache::has("telegram_edit_amount:{$chatId}")) {
            $this->quickAddHandler->handleEditAmountReply($user, $chatId, $text);

            return;
        }

        $this->quickAddHandler->handleQuickAdd($user, $chatId, $text);
    }
}
