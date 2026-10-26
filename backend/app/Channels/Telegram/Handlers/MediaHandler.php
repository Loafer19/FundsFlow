<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Agent\Documents\DocumentFile;
use App\Actions\Agent\Documents\DocumentImport;
use App\Actions\Telegram\AnalyzeReceiptAction;
use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\StoreTransactionAttachmentAction;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramHistory;
use App\Channels\Telegram\TelegramSupport;
use App\Enums\TransactionSource;
use App\Models\User;
use App\Support\ReceiptFiles;
use App\Support\TransactionAttachmentRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaHandler
{
    private const OUTDATED_DRAFT = 'This receipt card is outdated or was already handled. Use the latest one.';

    /** Whose pending slot the current message uses (see identity()). */
    private int|string|null $currentIdentity = null;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly CreateTransactionAction $createTransaction,
        private readonly StoreTransactionAttachmentAction $storeAttachment,
        private readonly AnalyzeReceiptAction $analyzeReceipt,
        private readonly TelegramHistory $history,
        private readonly DocumentImport $documents,
        private readonly TelegramChatSession $session,
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
        $this->currentIdentity = $this->identity($message, $chatId);

        // Documents to read (PDF, Word, CSV, Excel, JSON, OFX, QIF, HTML). A group of them is
        // collected first and becomes ONE import.
        if ($this->isReadableDocument($message)) {
            if (isset($message['media_group_id']) && is_string($message['media_group_id']) && $message['media_group_id'] !== '') {
                $this->bufferDocumentGroup($user, $chatId, $message);

                return;
            }
            $this->handleDocument($user, $chatId, $message);

            return;
        }

        if (isset($message['media_group_id']) && is_string($message['media_group_id']) && $message['media_group_id'] !== '') {
            $this->bufferAlbumItem($user, $chatId, $message);

            return;
        }

        $caption = trim((string) ($message['caption'] ?? ''));
        $this->history->input($chatId, $user, 'Sent a receipt ' . (isset($message['document']) ? 'file' : 'photo') . ($caption !== '' ? ": {$caption}" : ''));

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
            $this->client->sendMessage($chatId, 'Send a receipt photo (JPEG, PNG, WebP) or a file: PDF, Word (.docx), CSV, Excel (.xlsx), .ods, JSON, OFX / QFX, QIF or HTML.');

            return;
        }

        // "statement" / "виписка" with a screenshot: a transaction list, not a receipt.
        if (!isset($message['document']) && DocumentImport::isListCaption($caption) && !$this->quickAddWorks($caption, $user)) {
            $this->readScreenshots($user, $chatId, $this->identity($message, $chatId), [$file], $caption);

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

        Cache::put($bufferKey, $buffer, now()->addSeconds((int) config('telegram.album.collect_ttl_seconds')));

        // First item in the group waits briefly so siblings can arrive, then asks 1-vs-several.
        if (!Cache::add($debounceKey, true, now()->addSeconds((int) config('telegram.album.collect_ttl_seconds')))) {
            return;
        }

        sleep((int) config('telegram.album.debounce_seconds'));

        $this->promptAlbumChoice($user, $chatId, $groupId, $this->identity($message, $chatId));
    }

    private function quickAddWorks(string $caption, User $user): bool
    {
        $parsed = $caption !== '' ? $this->support->parseQuickAdd($caption, $user) : null;

        return is_array($parsed) && !isset($parsed['error']);
    }

    private function promptAlbumChoice(User $user, int|string $chatId, string $groupId, int|string|null $identity = null): void
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

        $caption = is_string($buffer['caption_hint'] ?? null) ? $buffer['caption_hint'] : '';
        $this->history->input($chatId, $user, "Sent {$n} receipt photo" . ($n === 1 ? '' : 's') . ($caption !== '' ? ": {$caption}" : ''));

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

        // "statement" / "виписка" in the caption: screenshots of a transaction list, no question.
        if (!is_array($buffer['caption_parsed'] ?? null) && DocumentImport::isListCaption($caption)) {
            Cache::forget($bufferKey);
            $this->readScreenshots($user, $chatId, $identity ?? $chatId, $files, $caption);

            return;
        }

        // Keep buffer for the choice callbacks (extend TTL).
        Cache::put($bufferKey, $buffer, now()->addMinutes((int) config('telegram.pending_ttl_minutes')));

        $this->client->sendMessage(
            $chatId,
            "Got {$n} photos. Is this one receipt, separate receipts, or screenshots of a transaction list (bank app history)?",
            $this->support->albumChoiceKeyboard($groupId),
        );
    }

    /**
     * Album → "Transaction list": all photos are read in one vision call and become ONE import.
     */
    public function handleAlbumList(User $user, int|string $chatId, string $groupId, int|string $identity): void
    {
        $buffer = $this->pullAlbumBuffer($chatId, $groupId);
        $files = $buffer !== null ? array_values(array_filter($buffer['files'] ?? [], 'is_array')) : [];
        if ($files === []) {
            $this->client->sendMessage($chatId, 'That album expired. Please send the photos again.');

            return;
        }
        $hint = is_string($buffer['caption_hint'] ?? null) ? $buffer['caption_hint'] : '';

        $this->readScreenshots($user, $chatId, $identity, $files, $hint);
    }

    /**
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $files
     */
    private function readScreenshots(User $user, int|string $chatId, int|string $identity, array $files, string $caption): void
    {
        $max = max(1, (int) config('agent.documents.max_screenshots', DocumentImport::MAX_SCREENSHOTS));
        $images = [];
        $skipped = 0;
        foreach (array_slice($files, 0, $max) as $file) {
            $downloaded = $this->analyzeReceipt->isVisionMime($file['mime']) ? $this->downloadTelegramFile($chatId, $file, notify: false) : null;
            if ($downloaded === null) {
                $skipped++;

                continue;
            }
            $images[] = ['bytes' => $downloaded[0], 'mime' => $file['mime']];
        }
        $skipped += max(0, count($files) - $max);
        if ($images === [] || (string) config('services.xai.api_key') === '') {
            $this->client->sendMessage($chatId, $images === []
                ? "Couldn't download the screenshots. Send them again (JPEG or PNG)."
                : "Screenshots can't be read right now. Export the statement as CSV or PDF from the bank app instead.");

            return;
        }

        $this->client->sendMessage($chatId, 'Reading ' . count($images) . ' screenshot' . (count($images) === 1 ? '' : 's') . '…');
        $result = $this->documents->fromScreenshots(
            $user,
            $images,
            $caption !== '' ? $caption : null,
            'Until then, export the statement as CSV, Excel, OFX or QIF from the bank app: those need no AI.',
        );
        if ($skipped > 0 && in_array($result['type'], ['confirm', 'ask'], true)) {
            $this->client->sendMessage($chatId, "{$skipped} image" . ($skipped === 1 ? " wasn't" : "s weren't") . " read (only the first {$max} JPEG / PNG screenshots are).");
        }

        $this->presentImport($user, $chatId, $identity, $result, 'screenshots');
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
            $this->putAlbumQueue($chatId, $files, $hint, $total);
        }

        $this->client->sendMessage($chatId, "Processing 1 of {$total}…");
        $this->handleReceiptWithoutAmount($user, $chatId, $first, $hint, albumPhoto: 1);
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
        $total = (int) ($queue['total'] ?? 0);
        $remaining = count($files);

        if ($files === []) {
            Cache::forget($this->albumQueueKey($chatId));
        } else {
            $this->putAlbumQueue($chatId, $files, $hint, $total);
        }

        $this->client->sendMessage(
            $chatId,
            $remaining > 0
                ? 'Next receipt (' . ($remaining + 1) . ' left after this)…'
                : 'Last receipt from the album…',
        );

        $this->handleReceiptWithoutAmount(
            $user,
            $chatId,
            $next,
            $hint,
            albumPhoto: $total > $remaining ? $total - $remaining : null,
        );
    }

    /**
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}>|null $allFiles
     * @param int|null $albumPhoto 1-based number of this photo in a "Separate" album: a photo that can't be
     *        downloaded is then skipped with a message naming it, and the next one is processed
     */
    private function handleReceiptWithoutAmount(
        User $user,
        int|string $chatId,
        array $file,
        ?string $captionHint,
        ?array $allFiles = null,
        ?int $albumPhoto = null,
    ): void {
        $attachmentFiles = $allFiles ?? [$file];

        $canUseVision = $this->analyzeReceipt->isVisionMime($file['mime'])
            && $this->analyzeReceipt->hasQuota($user)
            && (string) config('services.xai.api_key') !== '';

        if (!$canUseVision) {
            $this->fallbackToAmountKeyboard(
                $user,
                $chatId,
                $file,
                overLimit: !$this->analyzeReceipt->hasQuota($user),
                allFiles: $attachmentFiles,
            );

            return;
        }

        $this->client->sendMessage($chatId, 'Reading receipt…');

        $downloaded = $this->downloadTelegramFile($chatId, $file, notify: $albumPhoto === null);

        if ($downloaded === null) {
            if ($albumPhoto !== null) {
                // Otherwise the rest of the album would wait for a Confirm that never comes.
                $this->client->sendMessage($chatId, "Photo {$albumPhoto} couldn't be read and was skipped. Send it again with the amount, like -350 groceries.");
                $this->history->outcome($chatId, $user, "Receipt photo {$albumPhoto} skipped (couldn't be read).");
                $this->continueAlbumQueue($user, $chatId);
            }

            return;
        }

        [$contents] = $downloaded;
        $analysis = $this->analyzeReceipt->execute($user, $contents, $file['mime'], $captionHint);

        if ($analysis === null) {
            $this->fallbackToAmountKeyboard($user, $chatId, $file, overLimit: false, aiFailed: true, allFiles: $attachmentFiles);

            return;
        }

        $this->analyzeReceipt->consumeQuota($user);

        // One screenshot of a whole transaction list: its rows become an import (no extra assist).
        if ($albumPhoto === null && count($attachmentFiles) === 1 && is_array($analysis['transaction_list'] ?? null)) {
            Cache::forget($this->pendingKey($chatId));
            $this->presentImport($user, $chatId, $this->currentIdentity ?? $chatId, $this->documents->fromScreenshotRows($user, [$analysis['transaction_list']], $captionHint), 'a screenshot');

            return;
        }

        $this->presentAnalysis($user, $chatId, $file, $analysis, $attachmentFiles);
    }

    /**
     * Receipt draft card from an analysis (photo vision or a document), or the price question.
     *
     * @param array{file_id: string, name: string, mime: string, file_size: int} $file
     * @param array<string, mixed> $analysis
     * @param list<array{file_id: string, name: string, mime: string, file_size?: int}> $attachmentFiles [] = save without a file
     */
    private function presentAnalysis(User $user, int|string $chatId, array $file, array $analysis, array $attachmentFiles): void
    {
        $draft = $this->buildDraftFromAnalysis($file, $analysis, $user, $attachmentFiles);
        if ($attachmentFiles === []) {
            $draft['no_attachment'] = true;
        }

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

        Cache::forget($this->pendingKey($chatId));
        Cache::forget($this->awaitTextKey($chatId));

        $this->showDraft($user, $chatId, $draft);
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
        User $user,
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
            $this->client->sendMessage(
                $chatId,
                $this->analyzeReceipt->quota()->exhaustedMessage(
                    $user,
                    'Until then, pick an amount below or send it like -350 groceries.',
                ),
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
            Cache::forget($this->awaitTextKey($chatId));
            $this->showDraft($user, $chatId, $draft);

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
        Cache::forget($this->awaitTextKey($chatId));

        $this->showDraft($user, $chatId, $draft);
    }

    public function confirmDraft(User $user, int|string $chatId, string $nonce): void
    {
        $draft = $this->draftForCard($chatId, $nonce);

        if ($draft === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_DRAFT);

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
            // The card's buttons are gone after a tap, so ask for the amount here.
            $draft['awaiting_edit_amount'] = true;
            $this->putDraft($chatId, $draft);
            $this->client->sendMessage(
                $chatId,
                "Amount can't be zero. Send the amount like -350.",
                $this->support->mediaAmountKeyboard(),
            );

            return;
        }

        if (!$this->support->claimNonce($nonce)) {
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

    public function beginDraftAmountEdit(User $user, int|string $chatId, string $nonce): void
    {
        $draft = $this->draftForCard($chatId, $nonce);

        if ($draft === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_DRAFT);

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

    /**
     * Cancel from a draft card's button. False when the card is outdated, so
     * a stale Cancel can't discard a newer receipt.
     */
    public function cancelDraft(int|string $chatId, string $nonce): bool
    {
        if ($this->draftForCard($chatId, $nonce) === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_DRAFT);

            return false;
        }

        $this->cancelPendingMedia($chatId);

        return true;
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

        if ($files === []) {
            // Read from a document that is not an attachment type (Word).
            $this->support->sendSavedTransaction($chatId, $user, $transaction, '✅ Saved');

            return;
        }

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
        ], now()->addMinutes((int) config('telegram.pending_ttl_minutes')));
        Cache::forget($this->awaitTextKey($chatId));
    }

    /**
     * @param array<string, mixed> $pendingOrDraft
     * @return list<array{file_id: string, name: string, mime: string, file_size?: int}>
     */
    private function filesFromPendingOrDraft(array $pendingOrDraft): array
    {
        // A Word document is read for its text only; it is not an attachment type.
        if (($pendingOrDraft['no_attachment'] ?? false) === true) {
            return [];
        }

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
        $best = ReceiptFiles::pickBest($files);

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
    private function putAlbumQueue(int|string $chatId, array $files, ?string $captionHint, int $total = 0): void
    {
        Cache::put($this->albumQueueKey($chatId), [
            'files' => array_values($files),
            'caption_hint' => $captionHint,
            'total' => $total,
        ], now()->addMinutes((int) config('telegram.pending_ttl_minutes')));
    }

    /**
     * Show the draft card with a fresh nonce: buttons on any earlier card for
     * this chat stop working, so they can't confirm a different receipt.
     *
     * @param array<string, mixed> $draft
     */
    private function showDraft(User $user, int|string $chatId, array $draft): void
    {
        $draft['nonce'] = $this->support->newNonce();
        $this->putDraft($chatId, $draft);
        $this->support->sendReceiptDraft($chatId, $user, $draft);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function draftForCard(int|string $chatId, string $nonce): ?array
    {
        $draft = $this->getDraft($chatId);

        return is_array($draft) && ($draft['nonce'] ?? null) === $nonce ? $draft : null;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function putDraft(int|string $chatId, array $draft): void
    {
        Cache::put($this->draftKey($chatId), $draft, now()->addMinutes((int) config('telegram.pending_ttl_minutes')));
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
     * A document the assistant reads (PDF, Word, CSV, Excel/ODS), or one it explains it can't
     * (.xls, .doc). Photos and image files keep the receipt photo flow.
     *
     * @param array<string, mixed> $message
     */
    private function isReadableDocument(array $message): bool
    {
        if (!isset($message['document']) || !is_array($message['document'])) {
            return false;
        }
        $mime = (string) ($message['document']['mime_type'] ?? '');
        if (str_starts_with($mime, 'image/')) {
            return false;
        }

        return $mime === 'application/pdf' || DocumentFile::looksLikeDocument((string) ($message['document']['file_name'] ?? ''));
    }

    /**
     * PDF / Word: one receipt (the usual draft card; a PDF is attached) or a statement; CSV /
     * Excel / OFX / QIF / JSON / HTML: rows parsed locally. Many rows become ONE Confirm card
     * (agent_mutation import_transactions, applied by TelegramAgentToolExecutor in one DB
     * transaction), or first a question about tags that don't exist (see handleImportReply).
     *
     * @param array<string, mixed> $message
     */
    private function handleDocument(User $user, int|string $chatId, array $message): void
    {
        $document = $message['document'];
        $caption = trim((string) ($message['caption'] ?? ''));
        $name = TransactionAttachmentRules::sanitizeOriginalName((string) ($document['file_name'] ?? 'document'));
        $this->history->input($chatId, $user, "Sent a file {$name}" . ($caption !== '' ? ": {$caption}" : ''));

        $isPdf = ($document['mime_type'] ?? null) === 'application/pdf' || DocumentFile::extension($name) === 'pdf';

        // A PDF receipt with the amount in the caption: saved at once with the file, no AI (as before).
        if ($isPdf && $caption !== '' && $this->quickAddWorks($caption, $user) && $this->extractTelegramFile($message) !== null) {
            $this->handleSingleMedia($user, $chatId, $message);

            return;
        }

        $loaded = $this->loadDocument($chatId, $document, true);
        if (is_string($loaded)) {
            if ($loaded !== '') {
                $this->client->sendMessage($chatId, $loaded);
                $this->history->outcome($chatId, $user, "File {$name} not imported: {$loaded}");
            }

            return;
        }
        ['file' => $file, 'kind' => $kind, 'bytes' => $contents] = $loaded;

        $this->client->sendMessage($chatId, 'Reading ' . mb_strimwidth($name, 0, 60, '…') . '…');
        $result = $this->documents->fromFiles(
            $user,
            [['name' => $name, 'kind' => $kind, 'bytes' => $contents]],
            $caption !== '' ? $caption : null,
            $kind === DocumentFile::PDF
                ? 'Until then, pick an amount below or send it like -350 groceries.'
                : 'Until then, send the amount like -350 groceries, or a CSV with date and amount columns.',
        );

        if (in_array($result['type'], ['confirm', 'ask'], true)) {
            $this->presentImport($user, $chatId, $this->identity($message, $chatId), $result, $name);

            return;
        }

        if ($result['type'] === 'quota' && $kind === DocumentFile::PDF) {
            // Same as a receipt photo past the limit: the PDF waits for an amount.
            $this->cachePendingMedia($chatId, $file, [$file]);
            $this->forgetDraft($chatId);
            $this->client->sendMessage($chatId, (string) $result['message'], $this->support->mediaAmountKeyboard());

            return;
        }

        if ($result['type'] !== 'single') {
            $this->client->sendMessage($chatId, (string) $result['message']);
            $this->history->outcome($chatId, $user, "File {$name} not imported: " . $result['message']);

            return;
        }

        Cache::forget($this->pendingKey($chatId));
        Cache::forget($this->awaitTextKey($chatId));
        $this->presentAnalysis($user, $chatId, $file, $result['analysis'], $kind === DocumentFile::PDF ? [$file] : []);
    }

    /**
     * Download + check one Telegram document. Error text on failure ('' = already told the user).
     *
     * @param array<string, mixed> $document
     * @return array{file: array{file_id: string, name: string, mime: string, file_size: int}, kind: string, bytes: string, name: string}|string
     */
    private function loadDocument(int|string $chatId, array $document, bool $notify): array|string
    {
        $name = TransactionAttachmentRules::sanitizeOriginalName((string) ($document['file_name'] ?? 'document'));
        $ext = DocumentFile::extension($name);
        $isPdf = ($document['mime_type'] ?? null) === 'application/pdf' || $ext === 'pdf';
        $file = [
            'file_id' => (string) ($document['file_id'] ?? ''),
            'name' => $name,
            'mime' => $isPdf ? 'application/pdf' : (string) ($document['mime_type'] ?? ''),
            'file_size' => (int) ($document['file_size'] ?? 0),
        ];

        // Legacy formats (.xls, .doc…) get their hint without a download.
        $legacy = DocumentFile::legacyHint($name);
        if ($legacy !== null) {
            return $legacy;
        }

        $kind = $isPdf ? DocumentFile::PDF : (DocumentFile::EXTENSIONS[$ext] ?? DocumentFile::CSV);
        if ($file['file_size'] > DocumentFile::maxBytes($kind)) {
            return 'The file is larger than ' . DocumentFile::formatBytes(DocumentFile::maxBytes($kind)) . '. Split it or send a shorter period.';
        }

        $downloaded = $this->downloadTelegramFile($chatId, $file, $notify);
        if ($downloaded === null) {
            return $notify ? '' : "couldn't download it from Telegram";
        }
        [$contents] = $downloaded;

        $detected = DocumentFile::detect($contents, $name);
        if (isset($detected['error'])) {
            return (string) $detected['error'];
        }

        return ['file' => $file, 'kind' => $detected['kind'], 'bytes' => $contents, 'name' => $name];
    }

    /**
     * Documents sent together (a media group): collected for a moment like photo albums, then
     * read and merged into ONE import (up to DocumentFile::maxFiles(), maxTotalBytes() in all).
     *
     * @param array<string, mixed> $message
     */
    private function bufferDocumentGroup(User $user, int|string $chatId, array $message): void
    {
        $groupId = (string) $message['media_group_id'];
        $key = "telegram_doc_group:{$chatId}:{$groupId}";
        $ttl = now()->addSeconds((int) config('telegram.album.collect_ttl_seconds'));

        $buffer = Cache::get($key);
        $buffer = is_array($buffer) ? $buffer : ['messages' => [], 'caption' => ''];
        $ids = array_map(static fn (array $m) => (string) ($m['document']['file_id'] ?? ''), $buffer['messages']);
        if (!in_array((string) ($message['document']['file_id'] ?? ''), $ids, true)) {
            $buffer['messages'][] = $message;
        }
        $caption = trim((string) ($message['caption'] ?? ''));
        if ($caption !== '' && $buffer['caption'] === '') {
            $buffer['caption'] = $caption;
        }
        Cache::put($key, $buffer, $ttl);

        if (!Cache::add("{$key}:debounce", true, $ttl)) {
            return;
        }
        sleep((int) config('telegram.album.debounce_seconds'));

        $buffer = Cache::pull($key);
        $messages = is_array($buffer) ? array_values($buffer['messages'] ?? []) : [];
        if ($messages === []) {
            return;
        }
        if (count($messages) === 1) {
            $single = $messages[0];
            if (($single['caption'] ?? '') === '' && ($buffer['caption'] ?? '') !== '') {
                $single['caption'] = $buffer['caption'];
            }
            $this->handleDocument($user, $chatId, $single);

            return;
        }

        $this->handleDocumentGroup($user, $chatId, $this->identity($message, $chatId), $messages, (string) ($buffer['caption'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function handleDocumentGroup(User $user, int|string $chatId, int|string $identity, array $messages, string $caption): void
    {
        $max = DocumentFile::maxFiles();
        $names = array_map(static fn (array $m) => TransactionAttachmentRules::sanitizeOriginalName((string) ($m['document']['file_name'] ?? 'document')), $messages);
        $this->history->input($chatId, $user, 'Sent ' . count($messages) . ' files: ' . implode(', ', $names) . ($caption !== '' ? ": {$caption}" : ''));

        $notes = [];
        if (count($messages) > $max) {
            $notes[] = 'Only the first ' . $max . ' files are read at once; skipped: ' . implode(', ', array_slice($names, $max)) . '.';
            $messages = array_slice($messages, 0, $max);
        }
        $total = array_sum(array_map(static fn (array $m) => (int) ($m['document']['file_size'] ?? 0), $messages));
        if ($total > DocumentFile::maxTotalBytes()) {
            $this->client->sendMessage($chatId, 'These files are ' . DocumentFile::formatBytes($total) . ' together; up to '
                . DocumentFile::formatBytes(DocumentFile::maxTotalBytes()) . ' at once. Send fewer files or shorter periods.');

            return;
        }

        $this->client->sendMessage($chatId, 'Reading ' . count($messages) . ' files…');
        $files = [];
        foreach ($messages as $i => $m) {
            $loaded = $this->loadDocument($chatId, is_array($m['document'] ?? null) ? $m['document'] : [], false);
            if (is_string($loaded)) {
                $notes[] = "⚠️ {$names[$i]}: {$loaded}";

                continue;
            }
            $files[] = ['name' => $loaded['name'], 'kind' => $loaded['kind'], 'bytes' => $loaded['bytes']];
        }
        if ($files === []) {
            $message = "Nothing to import:\n" . implode("\n", $notes);
            $this->client->sendMessage($chatId, $message);
            $this->history->outcome($chatId, $user, $message);

            return;
        }

        $result = $this->documents->fromFiles(
            $user,
            $files,
            $caption !== '' ? $caption : null,
            'Until then, send CSV / Excel / OFX / QIF files with date and amount columns: those need no AI.',
        );
        if ($notes !== [] && in_array($result['type'], ['confirm', 'ask'], true)) {
            $this->client->sendMessage($chatId, implode("\n", $notes));
        }
        $this->presentImport($user, $chatId, $identity, $result, count($files) . ' files');
    }

    /**
     * DocumentImport result → Confirm card, the tags question (buttons + free-text reply), or text.
     *
     * @param array<string, mixed> $result
     */
    private function presentImport(User $user, int|string $chatId, int|string $identity, array $result, string $what): void
    {
        if ($result['type'] === 'confirm') {
            $this->stageImport($user, $chatId, $identity, $result);

            return;
        }
        if ($result['type'] === 'ask') {
            $nonce = $this->support->newNonce();
            // One pending slot per chat (shared with assistant previews): this replaces an older one.
            $this->session->setPending($identity, $chatId, ['type' => 'import_tags', 'draft' => $result['draft'], 'nonce' => $nonce]);
            $this->client->sendMessage(
                $chatId,
                mb_substr((string) $result['question'], 0, 3800) . "\n\nOr just write what to do, e.g. “create Cafe, map Taxi → Transport”.",
                $this->support->importTagsKeyboard($nonce),
            );
            $this->history->proposal($chatId, $user, 'Import of ' . $what . ': asked about tags that don\'t exist', $nonce);

            return;
        }
        if ($result['type'] === 'single') {
            $this->client->sendMessage($chatId, 'That file looks like one receipt. Send it on its own to save it as one transaction.');

            return;
        }
        $this->client->sendMessage($chatId, (string) ($result['message'] ?? 'Nothing to import.'));
        $this->history->outcome($chatId, $user, "Import of {$what} not done: " . ($result['message'] ?? ''));
    }

    /**
     * A text reply while the tags question is open: create / map / without, or another edit of
     * the import ("only October", "skip Rent"). False when it isn't about the import (the
     * question is dropped and the message is handled as usual).
     */
    public function handleImportReply(User $user, int|string $chatId, int|string $identity, string $text): bool
    {
        $pending = $this->session->get($identity, $chatId)['pending'] ?? null;
        if (!is_array($pending) || ($pending['type'] ?? null) !== 'import_tags' || !is_array($pending['draft'] ?? null)) {
            return false;
        }
        $this->session->clearPending($identity, $chatId);

        $result = $this->documents->reply($user, $pending['draft'], $text);
        if ($result !== null) {
            $this->history->input($chatId, $user, $text);
        }
        if ($result === null) {
            $this->client->sendMessage($chatId, 'The import was dropped. Send the files again to import them.');

            return false;
        }
        if ($result['type'] === 'cancelled') {
            $this->client->sendMessage($chatId, 'Import cancelled.');
            $this->history->outcome($chatId, $user, 'Import cancelled.');

            return true;
        }
        $this->presentImport($user, $chatId, $identity, $result, 'files');

        return true;
    }

    /**
     * The buttons under the tags question: imp:create|none|cancel:{nonce}.
     */
    public function handleImportButton(User $user, int|string $chatId, int|string $identity, string $choice, string $nonce): string
    {
        $pending = $this->session->get($identity, $chatId)['pending'] ?? null;
        if (!is_array($pending) || ($pending['type'] ?? null) !== 'import_tags' || ($pending['nonce'] ?? null) !== $nonce) {
            return 'This question is outdated. Send the files again.';
        }
        $text = match ($choice) {
            'create' => DocumentImport::SUGGESTIONS[0],
            'none' => DocumentImport::SUGGESTIONS[1],
            default => DocumentImport::SUGGESTIONS[2],
        };
        $this->handleImportReply($user, $chatId, $identity, $text);

        return $choice === 'cancel' ? 'Cancelled' : 'OK';
    }

    /**
     * @param array<string, mixed> $result DocumentImport confirm result
     */
    private function stageImport(User $user, int|string $chatId, int|string $identity, array $result): void
    {
        $nonce = $this->support->newNonce();
        // One pending slot per chat (shared with assistant previews): this replaces an older one.
        $this->session->setPending($identity, $chatId, [
            'type' => 'agent_mutation',
            'action' => 'import_transactions',
            'payload' => $result['payload'],
            'preview' => $result['preview'],
            'nonce' => $nonce,
        ]);
        $this->session->setSummary($identity, $chatId, mb_substr('Import preview shown: ' . $result['summary'], 0, 500));
        $this->client->sendMessage(
            $chatId,
            mb_substr($result['preview'], 0, 3800) . "\n\nImport these transactions?",
            $this->support->nlAgentConfirmKeyboard($nonce),
        );
        $this->history->proposal($chatId, $user, 'Import preview: ' . $result['summary'], $nonce);
    }

    /**
     * Whose pending slot: the sender in a group, the chat itself in a private chat.
     *
     * @param array<string, mixed> $message
     */
    private function identity(array $message, int|string $chatId): int|string
    {
        $from = $message['from']['id'] ?? null;

        return is_int($from) || (is_string($from) && $from !== '') ? $from : $chatId;
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
