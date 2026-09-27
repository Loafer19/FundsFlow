<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Telegram\AnalyzeReceiptAction;
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

    private const ALBUM_DEBOUNCE_SECONDS = 3;

    private const ALBUM_COLLECT_TTL_SECONDS = 60;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly CreateTransactionAction $createTransaction,
        private readonly StoreTransactionAttachmentAction $storeAttachment,
        private readonly AnalyzeReceiptAction $analyzeReceipt,
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

        if (isset($message['media_group_id']) && is_string($message['media_group_id']) && $message['media_group_id'] !== '') {
            $this->bufferAlbumItem($user, $chatId, $message);

            return;
        }

        $this->handleSingleMedia($user, $chatId, $message);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleSingleMedia(User $user, int|string $chatId, array $message): void
    {
        $caption = trim((string) ($message['caption'] ?? ''));
        $file = $this->extractTelegramFile($message);

        if ($file === null) {
            $this->client->sendMessage($chatId, 'Only JPEG, PNG, WebP, and PDF receipts are supported.');

            return;
        }

        // Caption with parseable amount → instant create (no AI).
        if ($caption !== '') {
            $parsed = $this->support->parseQuickAdd($caption, $user);

            if ($parsed !== null && !isset($parsed['error'])) {
                $this->createTransactionWithAttachments(
                    $user,
                    $chatId,
                    $parsed['at'],
                    $parsed['amount'],
                    $parsed['note'],
                    [$file],
                );

                return;
            }

            if ($parsed !== null && isset($parsed['error'])) {
                $this->client->sendMessage($chatId, $parsed['error']);

                return;
            }
            // Caption without amount → continue to AI / fallback below.
        }

        $this->handleReceiptWithoutAmount($user, $chatId, $file, $caption !== '' ? $caption : null);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function bufferAlbumItem(User $user, int|string $chatId, array $message): void
    {
        $groupId = (string) $message['media_group_id'];
        $file = $this->extractTelegramFile($message);
        $caption = trim((string) ($message['caption'] ?? ''));

        if ($file === null) {
            return;
        }

        $bufferKey = $this->albumBufferKey($chatId, $groupId);
        $debounceKey = $this->albumDebounceKey($chatId, $groupId);

        $buffer = Cache::get($bufferKey);
        if (!is_array($buffer)) {
            $buffer = [
                'group_id' => $groupId,
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'files' => [],
                'caption_parsed' => null,
                'caption_hint' => null,
            ];
        }

        $seen = [];
        foreach ($buffer['files'] as $existing) {
            if (is_array($existing) && isset($existing['file_id'])) {
                $seen[(string) $existing['file_id']] = true;
            }
        }

        if (!isset($seen[$file['file_id']])) {
            $buffer['files'][] = $file;
        }

        if ($caption !== '') {
            if ($buffer['caption_hint'] === null) {
                $buffer['caption_hint'] = $caption;
            }

            if ($buffer['caption_parsed'] === null) {
                $parsed = $this->support->parseQuickAdd($caption, $user);
                if ($parsed !== null && !isset($parsed['error'])) {
                    $buffer['caption_parsed'] = $parsed;
                }
            }
        }

        Cache::put($bufferKey, $buffer, now()->addSeconds(self::ALBUM_COLLECT_TTL_SECONDS));

        // First item in the group waits briefly so siblings can arrive, then asks 1-vs-several.
        if (!Cache::add($debounceKey, true, now()->addSeconds(self::ALBUM_COLLECT_TTL_SECONDS))) {
            return;
        }

        sleep(self::ALBUM_DEBOUNCE_SECONDS);

        $this->promptAlbumChoice($user, $chatId, $groupId);
    }

    private function promptAlbumChoice(User $user, int|string $chatId, string $groupId): void
    {
        $bufferKey = $this->albumBufferKey($chatId, $groupId);
        $buffer = Cache::get($bufferKey);

        if (!is_array($buffer) || !isset($buffer['files']) || !is_array($buffer['files']) || $buffer['files'] === []) {
            return;
        }

        $files = array_values(array_filter($buffer['files'], 'is_array'));
        $n = count($files);

        if ($n === 0) {
            return;
        }

        if ($n === 1) {
            Cache::forget($bufferKey);
            $file = $files[0];
            $hint = isset($buffer['caption_hint']) && is_string($buffer['caption_hint'])
                ? $buffer['caption_hint']
                : null;

            if (isset($buffer['caption_parsed']) && is_array($buffer['caption_parsed'])) {
                $parsed = $buffer['caption_parsed'];
                $this->createTransactionWithAttachments(
                    $user,
                    $chatId,
                    $parsed['at'],
                    $parsed['amount'],
                    $parsed['note'],
                    [$file],
                );

                return;
            }

            $this->handleReceiptWithoutAmount($user, $chatId, $file, $hint);

            return;
        }

        // Keep buffer for the choice callbacks (extend TTL).
        Cache::put($bufferKey, $buffer, now()->addMinutes(self::PENDING_TTL_MINUTES));

        $this->client->sendMessage(
            $chatId,
            "Got {$n} photos. Is this one receipt or separate transactions?",
            $this->support->albumChoiceKeyboard($groupId),
        );
    }

    public function handleAlbumOne(User $user, int|string $chatId, string $groupId): void
    {
        $buffer = $this->pullAlbumBuffer($chatId, $groupId);

        if ($buffer === null) {
            $this->client->sendMessage($chatId, 'That album expired. Please send the photos again.');

            return;
        }

        $files = array_values(array_filter($buffer['files'] ?? [], 'is_array'));
        if ($files === []) {
            $this->client->sendMessage($chatId, 'That album expired. Please send the photos again.');

            return;
        }

        $files = array_slice($files, 0, TransactionAttachmentRules::MAX_PER_TRANSACTION);

        // Caption amount on album + One → skip AI, create with all attachments.
        if (isset($buffer['caption_parsed']) && is_array($buffer['caption_parsed'])) {
            $parsed = $buffer['caption_parsed'];
            $this->createTransactionWithAttachments(
                $user,
                $chatId,
                $parsed['at'],
                $parsed['amount'],
                $parsed['note'],
                $files,
            );

            return;
        }

        $primary = $this->pickBestAlbumFile($files);
        $hint = isset($buffer['caption_hint']) && is_string($buffer['caption_hint'])
            ? $buffer['caption_hint']
            : null;

        $this->handleReceiptWithoutAmount($user, $chatId, $primary, $hint, $files);
    }

    public function handleAlbumEach(User $user, int|string $chatId, string $groupId): void
    {
        $buffer = $this->pullAlbumBuffer($chatId, $groupId);

        if ($buffer === null) {
            $this->client->sendMessage($chatId, 'That album expired. Please send the photos again.');

            return;
        }

        $files = array_values(array_filter($buffer['files'] ?? [], 'is_array'));
        if ($files === []) {
            $this->client->sendMessage($chatId, 'That album expired. Please send the photos again.');

            return;
        }

        $total = count($files);
        $first = array_shift($files);
        $hint = isset($buffer['caption_hint']) && is_string($buffer['caption_hint'])
            ? $buffer['caption_hint']
            : null;

        if ($files !== []) {
            $this->putAlbumQueue($chatId, $files, $hint);
        }

        $this->client->sendMessage($chatId, "Processing 1 of {$total}…");
        $this->handleReceiptWithoutAmount($user, $chatId, $first, $hint);
    }

    /**
     * After Confirm/Cancel of the current draft (or fallback pending), process next queued album item.
     */
    public function continueAlbumQueue(User $user, int|string $chatId): void
    {
        $queue = Cache::get($this->albumQueueKey($chatId));
        if (!is_array($queue) || !isset($queue['files']) || !is_array($queue['files']) || $queue['files'] === []) {
            Cache::forget($this->albumQueueKey($chatId));

            return;
        }

        $files = array_values(array_filter($queue['files'], 'is_array'));
        if ($files === []) {
            Cache::forget($this->albumQueueKey($chatId));

            return;
        }

        $next = array_shift($files);
        $hint = isset($queue['caption_hint']) && is_string($queue['caption_hint']) ? $queue['caption_hint'] : null;

        if ($files === []) {
            Cache::forget($this->albumQueueKey($chatId));
        } else {
            $this->putAlbumQueue($chatId, $files, $hint);
        }

        $remaining = count($files);
        $this->client->sendMessage(
            $chatId,
            $remaining > 0
                ? 'Next receipt (' . ($remaining + 1) . ' left after this)…'
                : 'Last receipt from the album…',
        );

        $this->handleReceiptWithoutAmount($user, $chatId, $next, $hint);
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}>|null $allFiles
     */
    private function handleReceiptWithoutAmount(
        User $user,
        int|string $chatId,
        array $file,
        ?string $captionHint,
        ?array $allFiles = null,
    ): void {
        $attachmentFiles = $allFiles ?? [$file];

        $canUseVision = $this->analyzeReceipt->isVisionMime($file['mime'])
            && $this->analyzeReceipt->hasQuota($user)
            && (string) config('services.xai.api_key') !== '';

        if (!$canUseVision) {
            $this->fallbackToAmountKeyboard(
                $chatId,
                $file,
                overLimit: !$this->analyzeReceipt->hasQuota($user),
                allFiles: $attachmentFiles,
            );

            return;
        }

        $this->client->sendMessage($chatId, 'Reading receipt…');

        $downloaded = $this->downloadTelegramFile($chatId, $file);

        if ($downloaded === null) {
            return;
        }

        [$contents] = $downloaded;
        $analysis = $this->analyzeReceipt->execute($user, $contents, $file['mime'], $captionHint);

        if ($analysis === null) {
            $this->fallbackToAmountKeyboard($chatId, $file, overLimit: false, aiFailed: true, allFiles: $attachmentFiles);

            return;
        }

        $this->analyzeReceipt->consumeQuota($user);

        $draft = $this->buildDraftFromAnalysis($file, $analysis, $user, $attachmentFiles);

        if ($analysis['needs_price'] || $analysis['amount'] === null) {
            $draft['amount'] = null;
            $draft['awaiting_price'] = true;
            $this->putDraft($chatId, $draft);
            Cache::forget($this->pendingKey($chatId));
            Cache::forget($this->awaitTextKey($chatId));

            $this->client->sendMessage(
                $chatId,
                "Couldn't read a clear total (e.g. food photo). Send the amount like -350, or pick a button.",
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        $this->putDraft($chatId, $draft);
        Cache::forget($this->pendingKey($chatId));
        Cache::forget($this->awaitTextKey($chatId));

        $this->support->sendReceiptDraft($chatId, $user, $draft);
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @param array<string, mixed> $analysis
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $allFiles
     * @return array<string, mixed>
     */
    private function buildDraftFromAnalysis(array $file, array $analysis, User $user, array $allFiles): array
    {
        return [
            'file_id' => $file['file_id'],
            'name' => $file['name'],
            'mime' => $file['mime'],
            'file_size' => $file['file_size'],
            'files' => array_values($allFiles),
            'amount' => $analysis['amount'],
            'at' => $analysis['at'] ?? $user->todayDateString(),
            'note' => $analysis['note'],
            'suggested_tag_titles' => $analysis['suggested_tag_titles'],
            'category' => $analysis['category'],
            'needs_price' => $analysis['needs_price'],
            'confidence' => $analysis['confidence'],
            'awaiting_price' => false,
            'awaiting_edit_amount' => false,
        ];
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}>|null $allFiles
     */
    private function fallbackToAmountKeyboard(
        int|string $chatId,
        array $file,
        bool $overLimit = false,
        bool $aiFailed = false,
        ?array $allFiles = null,
    ): void {
        $files = $allFiles ?? [$file];
        $this->cachePendingMedia($chatId, $file, $files);
        $this->forgetDraft($chatId);

        if ($overLimit) {
            $limit = $this->analyzeReceipt->dailyLimit();
            $this->client->sendMessage(
                $chatId,
                "Daily AI receipt limit reached ({$limit}/day). Pick an amount or send like -350 groceries",
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        if ($aiFailed) {
            $this->client->sendMessage(
                $chatId,
                "Couldn't read that receipt automatically. Pick an amount or send like -350 groceries",
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        $this->client->sendMessage(
            $chatId,
            'Receipt received. Pick an amount or send like -350 groceries',
            $this->support->mediaAmountKeyboard(),
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

        $files = $this->filesFromPendingOrDraft($pending);

        $this->createTransactionWithAttachments(
            $user,
            $chatId,
            $parsed['at'],
            $parsed['amount'],
            $parsed['note'],
            $files,
            forgetPending: true,
        );

        $this->continueAlbumQueue($user, $chatId);
    }

    public function completePendingMediaAmount(User $user, int|string $chatId, float $amount): void
    {
        $draft = $this->getDraft($chatId);

        if (is_array($draft) && (($draft['awaiting_price'] ?? false) || ($draft['awaiting_edit_amount'] ?? false))) {
            if ($amount === 0.0) {
                $this->client->sendMessage($chatId, "Amount can't be zero");

                return;
            }

            $draft['amount'] = $amount;
            $draft['awaiting_price'] = false;
            $draft['awaiting_edit_amount'] = false;
            $draft['needs_price'] = false;
            $this->putDraft($chatId, $draft);
            Cache::forget($this->awaitTextKey($chatId));
            $this->support->sendReceiptDraft($chatId, $user, $draft);

            return;
        }

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

        $files = $this->filesFromPendingOrDraft($pending);

        $this->createTransactionWithAttachments(
            $user,
            $chatId,
            $user->todayDateString(),
            $amount,
            null,
            $files,
            forgetPending: true,
        );

        $this->continueAlbumQueue($user, $chatId);
    }

    /**
     * Apply typed amount (and optional note) to an AI receipt draft, then show Confirm card.
     */
    public function completeDraftAmount(User $user, int|string $chatId, string $text): void
    {
        $draft = $this->getDraft($chatId);

        if (!is_array($draft) || (!($draft['awaiting_price'] ?? false) && !($draft['awaiting_edit_amount'] ?? false))) {
            $this->client->sendMessage($chatId, 'No pending receipt draft. Send a photo again.');

            return;
        }

        $parsed = $this->support->parseQuickAdd($text, $user);

        if ($parsed === null) {
            $this->client->sendMessage(
                $chatId,
                'Send an amount like -350 or -350 groceries',
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        if (isset($parsed['error'])) {
            $this->client->sendMessage($chatId, $parsed['error']);

            return;
        }

        $draft['amount'] = $parsed['amount'];
        $draft['at'] = $parsed['at'];
        if ($parsed['note'] !== null) {
            $draft['note'] = $parsed['note'];
        }
        $draft['awaiting_price'] = false;
        $draft['awaiting_edit_amount'] = false;
        $draft['needs_price'] = false;
        $this->putDraft($chatId, $draft);
        Cache::forget($this->awaitTextKey($chatId));

        $this->support->sendReceiptDraft($chatId, $user, $draft);
    }

    public function confirmDraft(User $user, int|string $chatId): void
    {
        $draft = $this->getDraft($chatId);

        if (!is_array($draft)) {
            $this->client->sendMessage($chatId, 'No pending receipt draft. Send a photo again.');

            return;
        }

        if (($draft['awaiting_price'] ?? false) || ($draft['awaiting_edit_amount'] ?? false) || !isset($draft['amount']) || $draft['amount'] === null) {
            $this->client->sendMessage(
                $chatId,
                'Set an amount first (send like -350 or tap a button).',
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        $amount = (float) $draft['amount'];

        if ($amount === 0.0) {
            $this->client->sendMessage($chatId, "Amount can't be zero");

            return;
        }

        $tagIds = $this->support->matchSuggestedTagIds($user, $draft['suggested_tag_titles'] ?? []);
        $files = $this->filesFromPendingOrDraft($draft);

        $this->createTransactionWithAttachments(
            $user,
            $chatId,
            (string) ($draft['at'] ?? $user->todayDateString()),
            $amount,
            isset($draft['note']) && is_string($draft['note']) ? $draft['note'] : null,
            $files,
            forgetPending: true,
            tags: $tagIds,
        );

        $this->continueAlbumQueue($user, $chatId);
    }

    public function beginDraftAmountEdit(User $user, int|string $chatId): void
    {
        $draft = $this->getDraft($chatId);

        if (!is_array($draft)) {
            $this->client->sendMessage($chatId, 'No pending receipt draft. Send a photo again.');

            return;
        }

        $draft['awaiting_edit_amount'] = true;
        $draft['awaiting_price'] = false;
        $this->putDraft($chatId, $draft);

        $this->client->sendMessage(
            $chatId,
            'Send the new amount like +120 or -50.5',
            $this->support->mediaAmountKeyboard(),
        );
    }

    public function cancelPendingMedia(int|string $chatId): void
    {
        Cache::forget($this->pendingKey($chatId));
        Cache::forget($this->awaitTextKey($chatId));
        $this->forgetDraft($chatId);
    }

    public function hasAwaitingDraft(int|string $chatId): bool
    {
        $draft = $this->getDraft($chatId);

        return is_array($draft)
            && (($draft['awaiting_price'] ?? false) || ($draft['awaiting_edit_amount'] ?? false));
    }

    public function hasDraft(int|string $chatId): bool
    {
        return is_array($this->getDraft($chatId));
    }

    /**
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $files
     * @param list<int> $tags
     */
    private function createTransactionWithAttachments(
        User $user,
        int|string $chatId,
        string $at,
        float $amount,
        ?string $note,
        array $files,
        bool $forgetPending = false,
        array $tags = [],
    ): void {
        if ($files === []) {
            $this->client->sendMessage($chatId, 'No files to attach.');

            return;
        }

        $files = array_slice(array_values($files), 0, TransactionAttachmentRules::MAX_PER_TRANSACTION);

        $payload = [
            'at' => $at,
            'amount' => $amount,
            'note' => $note,
        ];

        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        $transaction = $this->createTransaction->execute($user, $payload, TransactionSource::Telegram);

        if ($forgetPending) {
            $this->cancelPendingMedia($chatId);
        }

        $attached = 0;
        $lastError = null;

        foreach ($files as $file) {
            $downloaded = $this->downloadTelegramFile($chatId, $file, notify: $attached === 0);

            if ($downloaded === null) {
                continue;
            }

            [$contents, $meta] = $downloaded;

            try {
                $this->storeAttachment->execute($user, $transaction, [
                    'name' => $meta['name'],
                    'mime' => $meta['mime'],
                    'contents' => $contents,
                ]);
                $attached++;
            } catch (ValidationException $exception) {
                $lastError = (string) collect($exception->errors())->flatten()->first();
            }
        }

        $transaction->load('attachments');

        if ($attached === 0) {
            $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Saved without file');
            if ($lastError !== null) {
                $this->client->sendMessage($chatId, $lastError);
            }

            return;
        }

        $headline = $attached > 1
            ? "✅ Saved with {$attached} receipts"
            : '✅ Saved with receipt';
        $this->support->sendSavedTransaction($chatId, $user, $transaction, $headline);

        if ($lastError !== null) {
            $this->client->sendMessage($chatId, $lastError);
        }
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size?: int} $file
     * @return array{0: string, 1: array{name: string, mime: string}}|null
     */
    private function downloadTelegramFile(int|string $chatId, array $file, bool $notify = true): ?array
    {
        try {
            $meta = $this->client->getFile($file['file_id']);
            $filePath = $meta['result']['file_path'] ?? null;
            $fileSize = (int) ($meta['result']['file_size'] ?? $file['file_size'] ?? 0);

            if (!$filePath) {
                if ($notify) {
                    $this->client->sendMessage($chatId, "Couldn't download that file from Telegram");
                }

                return null;
            }

            if ($fileSize > TransactionAttachmentRules::MAX_BYTES) {
                if ($notify) {
                    $this->client->sendMessage($chatId, 'Each attachment must be 8 MB or smaller.');
                }

                return null;
            }

            $contents = $this->client->downloadFile($filePath);
        } catch (Throwable) {
            if ($notify) {
                $this->client->sendMessage($chatId, "Couldn't download that file from Telegram");
            }

            return null;
        }

        return [$contents, ['name' => $file['name'], 'mime' => $file['mime']]];
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $allFiles
     */
    private function cachePendingMedia(int|string $chatId, array $file, array $allFiles = []): void
    {
        $files = $allFiles !== [] ? $allFiles : [$file];
        Cache::put($this->pendingKey($chatId), [
            'file_id' => $file['file_id'],
            'name' => $file['name'],
            'mime' => $file['mime'],
            'file_size' => $file['file_size'],
            'files' => array_values($files),
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));
        Cache::forget($this->awaitTextKey($chatId));
    }

    /**
     * @param array<string, mixed> $pendingOrDraft
     * @return list<array{file_id: string, name: string, mime: string, file_size?: int}>
     */
    private function filesFromPendingOrDraft(array $pendingOrDraft): array
    {
        if (isset($pendingOrDraft['files']) && is_array($pendingOrDraft['files']) && $pendingOrDraft['files'] !== []) {
            return array_values(array_filter($pendingOrDraft['files'], 'is_array'));
        }

        return [[
            'file_id' => (string) $pendingOrDraft['file_id'],
            'name' => (string) $pendingOrDraft['name'],
            'mime' => (string) $pendingOrDraft['mime'],
            'file_size' => (int) ($pendingOrDraft['file_size'] ?? 0),
        ]];
    }

    /**
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $files
     * @return array{file_id: string, name: string, mime: string, file_size: int}
     */
    private function pickBestAlbumFile(array $files): array
    {
        $best = $files[0];
        $bestScore = -1;

        foreach ($files as $file) {
            $mime = (string) ($file['mime'] ?? '');
            $size = (int) ($file['file_size'] ?? 0);
            $mimeBonus = match ($mime) {
                'image/jpeg' => 1_000_000_000,
                'image/png' => 900_000_000,
                'image/webp' => 800_000_000,
                default => 0,
            };
            $score = $mimeBonus + $size;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $file;
            }
        }

        return [
            'file_id' => (string) $best['file_id'],
            'name' => (string) $best['name'],
            'mime' => (string) $best['mime'],
            'file_size' => (int) ($best['file_size'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pullAlbumBuffer(int|string $chatId, string $groupId): ?array
    {
        $key = $this->albumBufferKey($chatId, $groupId);
        $buffer = Cache::pull($key);

        return is_array($buffer) ? $buffer : null;
    }

    /**
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $files
     */
    private function putAlbumQueue(int|string $chatId, array $files, ?string $captionHint): void
    {
        Cache::put($this->albumQueueKey($chatId), [
            'files' => array_values($files),
            'caption_hint' => $captionHint,
        ], now()->addMinutes(self::PENDING_TTL_MINUTES));
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function putDraft(int|string $chatId, array $draft): void
    {
        Cache::put($this->draftKey($chatId), $draft, now()->addMinutes(self::PENDING_TTL_MINUTES));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDraft(int|string $chatId): ?array
    {
        $draft = Cache::get($this->draftKey($chatId));

        return is_array($draft) ? $draft : null;
    }

    private function forgetDraft(int|string $chatId): void
    {
        Cache::forget($this->draftKey($chatId));
    }

    private function pendingKey(int|string $chatId): string
    {
        return "telegram_pending_media:{$chatId}";
    }

    private function awaitTextKey(int|string $chatId): string
    {
        return "telegram_pending_media_await_text:{$chatId}";
    }

    private function draftKey(int|string $chatId): string
    {
        return "telegram_receipt_draft:{$chatId}";
    }

    private function albumBufferKey(int|string $chatId, string $groupId): string
    {
        return "telegram_album_buffer:{$chatId}:{$groupId}";
    }

    private function albumDebounceKey(int|string $chatId, string $groupId): string
    {
        return "telegram_album_debounce:{$chatId}:{$groupId}";
    }

    private function albumQueueKey(int|string $chatId): string
    {
        return "telegram_album_queue:{$chatId}";
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
