<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Telegram\TranscribeTelegramAudioAction;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MessageHandler
{
    /** Telegram Bot API download limit for getFile. */
    private const MAX_VOICE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly AuthHandler $authHandler,
        private readonly MenuHandler $menuHandler,
        private readonly QuickAddHandler $quickAddHandler,
        private readonly MediaHandler $mediaHandler,
        private readonly NaturalLanguageHandler $naturalLanguageHandler,
        private readonly TelegramChatSession $session,
        private readonly TranscribeTelegramAudioAction $transcribeAudio,
    ) {}

    /**
     * @param array<string, mixed> $message
     */
    public function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $telegramUserId = isset($message['from']['id']) ? (int) $message['from']['id'] : $chatId;
        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '') {
            return;
        }

        $this->handleIncomingText($chatId, $telegramUserId, $text);
    }

    /**
     * Telegram voice note or audio file → STT → same path as typed text.
     *
     * @param array<string, mixed> $message
     */
    public function handleVoiceMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $telegramUserId = isset($message['from']['id']) ? (int) $message['from']['id'] : $chatId;

        $user = $this->support->resolveUser($chatId);

        if (!$user) {
            $this->client->sendMessage($chatId, "Your account isn't linked yet. Send /start to get started");

            return;
        }

        $this->session->touch($telegramUserId, $chatId);

        if (!$this->transcribeAudio->isConfigured()) {
            $this->client->sendMessage(
                $chatId,
                "Voice messages aren't available yet (speech-to-text isn't configured). Type your message instead.",
            );

            return;
        }

        if (!$this->transcribeAudio->hasQuota($user)) {
            $this->client->sendMessage(
                $chatId,
                $this->transcribeAudio->quota()->exhaustedMessage(
                    $user,
                    'Type your message instead, or try again after the reset. Settings in the Web UI shows your quota.',
                ),
            );

            return;
        }

        $file = $this->extractVoiceOrAudioFile($message);

        if ($file === null) {
            $this->client->sendMessage($chatId, "Couldn't read that voice message. Try again or type instead.");

            return;
        }

        $this->client->sendMessage($chatId, 'Listening…');

        $downloaded = $this->downloadVoiceFile($chatId, $file);

        if ($downloaded === null) {
            return;
        }

        [$contents, $meta] = $downloaded;
        $transcript = $this->transcribeAudio->execute($user, $contents, $meta['name'], $meta['mime']);

        if ($transcript === null) {
            $this->client->sendMessage(
                $chatId,
                "Couldn't transcribe that voice message. Try again or type instead.",
            );

            return;
        }

        if ($transcript === '') {
            $this->client->sendMessage(
                $chatId,
                "Didn't catch any speech. Try again closer to the mic, or type instead.",
            );

            return;
        }

        // One shared-quota decrement per voice message that successfully hits STT.
        // Downstream NL/receipt AI calls may consume additional units like typed text.
        $this->transcribeAudio->consumeQuota($user);
        $remainingAfterStt = $this->transcribeAudio->quota()->remaining($user);

        $this->client->sendMessage($chatId, 'Heard: ' . $transcript);

        // Caption-style: if the voice was sent with a caption, ignore it — transcript is the command.
        $this->handleIncomingText($chatId, $telegramUserId, $transcript, skipAuthGate: true, user: $user);

        // If NL also ran and consumed, it already sent a low-remaining hint.
        // Otherwise hint here when STT alone left the budget low.
        if ($this->transcribeAudio->quota()->remaining($user) === $remainingAfterStt) {
            $hint = $this->transcribeAudio->quota()->lowRemainingHint($user);
            if ($hint !== null) {
                $this->client->sendMessage($chatId, $hint);
            }
        }
    }

    /**
     * Shared entry for typed text and post-STT transcripts.
     */
    private function handleIncomingText(
        int|string $chatId,
        int|string $telegramUserId,
        string $text,
        bool $skipAuthGate = false,
        ?User $user = null,
    ): void {
        if (str_starts_with($text, '/start')) {
            $this->authHandler->handleStart([
                'chat' => ['id' => $chatId],
                'from' => ['id' => $telegramUserId],
                'text' => $text,
            ]);

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

        if ($user === null) {
            $user = $this->support->resolveUser($chatId);
        }

        if (!$user) {
            if ($text === '/unlink') {
                $this->client->sendMessage($chatId, "This chat isn't linked to a FundsFlow account");

                return;
            }

            if (!$skipAuthGate) {
                $this->client->sendMessage($chatId, "Your account isn't linked yet. Send /start to get started");
            }

            return;
        }

        $this->session->touch($telegramUserId, $chatId);

        match (true) {
            $text === '/month' || $text === '/analytics' || $text === TelegramSupport::MENU_MONTH => $this->menuHandler->sendMonthSummary($user, $chatId, $telegramUserId),
            $text === '/recent' || $text === TelegramSupport::MENU_RECENT => $this->menuHandler->sendRecent($user, $chatId, $telegramUserId),
            $text === '/tags' || $text === TelegramSupport::MENU_TAGS => $this->menuHandler->sendTags($user, $chatId, $telegramUserId),
            $text === '/budgets' || $text === TelegramSupport::MENU_BUDGETS => $this->menuHandler->sendBudgets($user, $chatId, $telegramUserId),
            $text === '/recurring' || $text === TelegramSupport::MENU_RECURRING => $this->menuHandler->sendRecurring($user, $chatId),
            $text === TelegramSupport::MENU_WEB => $this->support->sendMiniAppHint($chatId),
            $text === '/website' => $this->authHandler->sendWebsiteLoginCode($user, $chatId),
            $text === '/mute' => $this->authHandler->handleMute($chatId, true),
            $text === '/unmute' => $this->authHandler->handleMute($chatId, false),
            $text === '/unlink' => $this->authHandler->handleUnlink($chatId),
            str_starts_with($text, '/newtag') => $this->menuHandler->handleNewTag($user, $chatId, $text),
            default => $this->handleDefaultText($user, $chatId, $text, $telegramUserId),
        };
    }

    private function handleDefaultText(User $user, int|string $chatId, string $text, int|string $telegramUserId): void
    {
        if ($this->naturalLanguageHandler->handlePendingAskAnswer($user, $chatId, $text, $telegramUserId)) {
            return;
        }

        if ($this->naturalLanguageHandler->handlePendingRenameTitle($user, $chatId, $text, $telegramUserId)) {
            return;
        }

        if ($this->mediaHandler->hasAwaitingDraft($chatId)) {
            $this->mediaHandler->completeDraftAmount($user, $chatId, $text);

            return;
        }

        if (Cache::has("telegram_pending_media:{$chatId}") || Cache::has("telegram_pending_media_await_text:{$chatId}")) {
            $this->mediaHandler->completePendingMedia($user, $chatId, $text);

            return;
        }

        if (Cache::has("telegram_edit_amount:{$chatId}")) {
            $this->quickAddHandler->handleEditAmountReply($user, $chatId, $text);

            return;
        }

        $parsed = $this->support->parseQuickAdd($text, $user);

        if ($parsed !== null) {
            $this->quickAddHandler->handleQuickAdd($user, $chatId, $text);

            return;
        }

        if ($this->naturalLanguageHandler->tryHandle($user, $chatId, $text, $telegramUserId)) {
            return;
        }

        $this->client->sendMessage(
            $chatId,
            "Didn't recognize that. Format: -350 groceries (minus is an expense, plus is income). "
                . 'Prefix a date like "20.08 -350 groceries" to log a past day. '
                . 'Or try: create tags groceries, coffee',
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array{file_id: string, name: string, mime: string, file_size: int}|null
     */
    private function extractVoiceOrAudioFile(array $message): ?array
    {
        if (isset($message['voice']) && is_array($message['voice'])) {
            $voice = $message['voice'];

            return [
                'file_id' => (string) ($voice['file_id'] ?? ''),
                'name' => 'voice.ogg',
                'mime' => (string) ($voice['mime_type'] ?? 'audio/ogg'),
                'file_size' => (int) ($voice['file_size'] ?? 0),
            ];
        }

        if (isset($message['audio']) && is_array($message['audio'])) {
            $audio = $message['audio'];
            $mime = (string) ($audio['mime_type'] ?? 'audio/mpeg');
            $name = (string) ($audio['file_name'] ?? 'audio.mp3');

            return [
                'file_id' => (string) ($audio['file_id'] ?? ''),
                'name' => $name !== '' ? $name : 'audio.mp3',
                'mime' => $mime !== '' ? $mime : 'audio/mpeg',
                'file_size' => (int) ($audio['file_size'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * Download without receipt JPEG/PDF MIME rules.
     *
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @return array{0: string, 1: array{name: string, mime: string}}|null
     */
    private function downloadVoiceFile(int|string $chatId, array $file): ?array
    {
        if ($file['file_id'] === '') {
            $this->client->sendMessage($chatId, "Couldn't download that voice message from Telegram");

            return null;
        }

        try {
            $meta = $this->client->getFile($file['file_id']);
            $filePath = $meta['result']['file_path'] ?? null;
            $fileSize = (int) ($meta['result']['file_size'] ?? $file['file_size'] ?? 0);

            if (!$filePath) {
                $this->client->sendMessage($chatId, "Couldn't download that voice message from Telegram");

                return null;
            }

            if ($fileSize > self::MAX_VOICE_BYTES) {
                $this->client->sendMessage($chatId, 'That voice message is too large (max 20 MB).');

                return null;
            }

            $contents = $this->client->downloadFile($filePath);
        } catch (Throwable) {
            $this->client->sendMessage($chatId, "Couldn't download that voice message from Telegram");

            return null;
        }

        return [$contents, ['name' => $file['name'], 'mime' => $file['mime']]];
    }
}
