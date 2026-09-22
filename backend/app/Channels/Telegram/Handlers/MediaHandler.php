<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\StoreTransactionAttachmentAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Enums\TransactionSource;
use App\Models\User;
use App\Support\TransactionAttachmentRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaHandler
{
    private const PENDING_TTL_MINUTES = 15;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly CreateTransactionAction $createTransaction,
        private readonly StoreTransactionAttachmentAction $storeAttachment,
    ) {}

    /**
     * @param array<string, mixed> $message
     */
    public function handleMediaMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $user = $this->support->resolveUser($chatId);

        if (!$user) {
            $this->client->sendMessage($chatId, "Your account isn't linked yet. Send /start to get started");

            return;
        }

        $caption = trim((string) ($message['caption'] ?? ''));
        $file = $this->extractTelegramFile($message);

        if ($file === null) {
            $this->client->sendMessage($chatId, 'Only JPEG, PNG, WebP, and PDF receipts are supported.');

            return;
        }

        if ($caption === '') {
            $this->cachePendingMedia($chatId, $file);
            $this->client->sendMessage(
                $chatId,
                'Receipt received. Pick an amount or send like -350 groceries',
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        $parsed = $this->support->parseQuickAdd($caption, $user);

        if ($parsed === null) {
            $this->client->sendMessage(
                $chatId,
                'Caption must look like "-350 groceries" or "20.08 -350 groceries".',
                $this->support->menuKeyboard(),
            );

            return;
        }

        if (isset($parsed['error'])) {
            $this->client->sendMessage($chatId, $parsed['error']);

            return;
        }

        $this->createTransactionWithAttachment(
            $user,
            $chatId,
            $parsed['at'],
            $parsed['amount'],
            $parsed['note'],
            $file,
        );
    }

    public function completePendingMedia(User $user, int|string $chatId, string $text): void
    {
        $pending = Cache::get($this->pendingKey($chatId));

        if (!is_array($pending)) {
            Cache::forget($this->awaitTextKey($chatId));
            $this->client->sendMessage($chatId, 'No pending receipt. Send a photo or PDF again.');

            return;
        }

        $parsed = $this->support->parseQuickAdd($text, $user);

        if ($parsed === null) {
            $this->client->sendMessage(
                $chatId,
                'Send an amount like -350 groceries, or tap a button.',
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        if (isset($parsed['error'])) {
            $this->client->sendMessage($chatId, $parsed['error']);

            return;
        }

        Cache::forget($this->awaitTextKey($chatId));

        $this->createTransactionWithAttachment(
            $user,
            $chatId,
            $parsed['at'],
            $parsed['amount'],
            $parsed['note'],
            $pending,
            forgetPending: true,
        );
    }

    public function completePendingMediaAmount(User $user, int|string $chatId, float $amount): void
    {
        $pending = Cache::get($this->pendingKey($chatId));

        if (!is_array($pending)) {
            $this->client->sendMessage($chatId, 'No pending receipt. Send a photo or PDF again.');

            return;
        }

        if ($amount === 0.0) {
            $this->client->sendMessage($chatId, "Amount can't be zero");

            return;
        }

        Cache::forget($this->awaitTextKey($chatId));

        $this->createTransactionWithAttachment(
            $user,
            $chatId,
            $user->todayDateString(),
            $amount,
            null,
            $pending,
            forgetPending: true,
        );
    }

    public function cancelPendingMedia(int|string $chatId): void
    {
        Cache::forget($this->pendingKey($chatId));
        Cache::forget($this->awaitTextKey($chatId));
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size?: int} $file
     */
    private function createTransactionWithAttachment(
        User $user,
        int|string $chatId,
        string $at,
        float $amount,
        ?string $note,
        array $file,
        bool $forgetPending = false,
    ): void {
        $downloaded = $this->downloadTelegramFile($chatId, $file);

        if ($downloaded === null) {
            return;
        }

        [$contents, $meta] = $downloaded;

        $transaction = $this->createTransaction->execute($user, [
            'at' => $at,
            'amount' => $amount,
            'note' => $note,
        ], TransactionSource::Telegram);

        if ($forgetPending) {
            $this->cancelPendingMedia($chatId);
        }

        try {
            $this->storeAttachment->execute($user, $transaction, [
                'name' => $meta['name'],
                'mime' => $meta['mime'],
                'contents' => $contents,
            ]);
            $transaction->load('attachments');
        } catch (ValidationException $exception) {
            $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Saved without file');
            $this->client->sendMessage(
                $chatId,
                (string) collect($exception->errors())->flatten()->first(),
            );

            return;
        }

        $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Saved with receipt');
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size?: int} $file
     * @return array{0: string, 1: array{name: string, mime: string}}|null
     */
    private function downloadTelegramFile(int|string $chatId, array $file): ?array
    {
        try {
            $meta = $this->client->getFile($file['file_id']);
            $filePath = $meta['result']['file_path'] ?? null;
            $fileSize = (int) ($meta['result']['file_size'] ?? $file['file_size'] ?? 0);

            if (!$filePath) {
                $this->client->sendMessage($chatId, "Couldn't download that file from Telegram");

                return null;
            }

            if ($fileSize > TransactionAttachmentRules::MAX_BYTES) {
                $this->client->sendMessage($chatId, 'Each attachment must be 8 MB or smaller.');

                return null;
            }

            $contents = $this->client->downloadFile($filePath);
        } catch (Throwable) {
            $this->client->sendMessage($chatId, "Couldn't download that file from Telegram");

            return null;
        }

        return [$contents, ['name' => $file['name'], 'mime' => $file['mime']]];
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     */
    private function cachePendingMedia(int|string $chatId, array $file): void
    {
        Cache::put($this->pendingKey($chatId), [
            'file_id' => $file['file_id'],
            'name' => $file['name'],
            'mime' => $file['mime'],
            'file_size' => $file['file_size'],
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));
        Cache::forget($this->awaitTextKey($chatId));
    }

    private function pendingKey(int|string $chatId): string
    {
        return "telegram_pending_media:{$chatId}";
    }

    private function awaitTextKey(int|string $chatId): string
    {
        return "telegram_pending_media_await_text:{$chatId}";
    }

    /**
     * @param array<string, mixed> $message
     * @return array{file_id: string, name: string, mime: string, file_size: int}|null
     */
    public function extractTelegramFile(array $message): ?array
    {
        if (isset($message['document']) && is_array($message['document'])) {
            $document = $message['document'];
            $mime = (string) ($document['mime_type'] ?? '');

            if (!isset(TransactionAttachmentRules::ALLOWED_MIMES[$mime])) {
                return null;
            }

            return [
                'file_id' => (string) $document['file_id'],
                'name' => (string) ($document['file_name'] ?? ('receipt.' . TransactionAttachmentRules::ALLOWED_MIMES[$mime])),
                'mime' => $mime,
                'file_size' => (int) ($document['file_size'] ?? 0),
            ];
        }

        if (isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== []) {
            $photo = $message['photo'][array_key_last($message['photo'])];

            return [
                'file_id' => (string) $photo['file_id'],
                'name' => 'receipt.jpg',
                'mime' => 'image/jpeg',
                'file_size' => (int) ($photo['file_size'] ?? 0),
            ];
        }

        return null;
    }
}
