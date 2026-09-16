<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\StoreTransactionAttachmentAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Enums\TransactionSource;
use App\Support\TransactionAttachmentRules;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaHandler
{
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

        if ($caption === '') {
            $this->client->sendMessage(
                $chatId,
                'Add a caption with the amount, e.g. "-350 groceries" (photo or PDF).',
                $this->support->menuKeyboard(),
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

        $file = $this->extractTelegramFile($message);

        if ($file === null) {
            $this->client->sendMessage($chatId, 'Only JPEG, PNG, WebP, and PDF receipts are supported.');

            return;
        }

        try {
            $meta = $this->client->getFile($file['file_id']);
            $filePath = $meta['result']['file_path'] ?? null;
            $fileSize = (int) ($meta['result']['file_size'] ?? $file['file_size'] ?? 0);

            if (!$filePath) {
                $this->client->sendMessage($chatId, "Couldn't download that file from Telegram");

                return;
            }

            if ($fileSize > TransactionAttachmentRules::MAX_BYTES) {
                $this->client->sendMessage($chatId, 'Each attachment must be 8 MB or smaller.');

                return;
            }

            $contents = $this->client->downloadFile($filePath);
        } catch (Throwable) {
            $this->client->sendMessage($chatId, "Couldn't download that file from Telegram");

            return;
        }

        $transaction = $this->createTransaction->execute($user, [
            'at' => $parsed['at'],
            'amount' => $parsed['amount'],
            'note' => $parsed['note'],
        ], TransactionSource::Telegram);

        try {
            $this->storeAttachment->execute($user, $transaction, [
                'name' => $file['name'],
                'mime' => $file['mime'],
                'contents' => $contents,
            ]);
            $transaction->load('attachments');
        } catch (ValidationException $exception) {
            $this->client->sendMessage(
                $chatId,
                "✅ Saved without file\n"
                    . $this->support->formatTransactionLine($user, $transaction) . "\n"
                    . $this->support->formatTagList($transaction) . "\n"
                    . collect($exception->errors())->flatten()->first(),
                $this->support->tagKeyboard($user, $transaction),
            );

            return;
        }

        $this->client->sendMessage(
            $chatId,
            "✅ Saved with receipt\n" . $this->support->formatTransactionLine($user, $transaction) . "\n" . $this->support->formatTagList($transaction),
            $this->support->tagKeyboard($user, $transaction),
        );
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
