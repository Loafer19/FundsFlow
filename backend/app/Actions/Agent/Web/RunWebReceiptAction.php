<?php

namespace App\Actions\Agent\Web;

use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Agent\Documents\DocumentFile;
use App\Actions\Agent\Documents\DocumentImport;
use App\Actions\Tags\MatchTagTitlesAction;
use App\Actions\Telegram\AnalyzeReceiptAction;
use App\Models\User;
use App\Support\QuickAddParser;
use App\Support\ReceiptFiles;
use App\Support\TransactionAttachmentRules;
use App\Support\UserFormatter;

/**
 * Receipt photos in the web assistant: the Telegram receipt flow (MediaHandler)
 * on the web transport, built on the same shared pieces (AnalyzeReceiptAction,
 * QuickAddParser, MatchTagTitlesAction, ReceiptFiles) and the same rules:
 *
 * - caption with an amount ("-350 groceries") skips AI;
 * - 2+ photos ask "one transaction, separate, or a transaction list?" first (a caption like
 *   "statement" / "виписка" picks the list at once): list screenshots become ONE bulk import;
 * - one: vision reads the best photo (JPEG > PNG > WebP, then largest), all photos are attached;
 * - separate: vision reads every photo;
 * - one quota unit per successful analysis, none when the AI call fails;
 * - no quota / AI off / no clear total -> ask for the amount.
 *
 * Unlike Telegram, which saves a caption amount at once, every result is a Confirm card
 * (staged create_transaction(s) with the photos as attachments).
 */
final class RunWebReceiptAction
{
    public const ALBUM_ONE = 'One transaction';

    public const ALBUM_EACH = 'Separate';

    public const ALBUM_LIST = 'Transaction list';

    public const CANCEL = 'Cancel';

    public const PRICE_SUGGESTIONS = ['-100', '-500', self::CANCEL];

    public function __construct(
        private readonly AnalyzeReceiptAction $analyze,
        private readonly QuickAddParser $quickAdd,
        private readonly MatchTagTitlesAction $matchTags,
        private readonly WebAgentUploads $uploads,
        private readonly AgentToolExecutor $tools,
        private readonly DocumentImport $documents,
    ) {}

    /**
     * A message with 2+ documents: all are read, merged (rows in several files counted once) and
     * shown as ONE import card. The files are not kept (a receipt among them becomes a plain row).
     *
     * @param non-empty-list<array{id: string, name: string, mime: string, file_size: int}> $files
     */
    public function startDocuments(User $user, WebAgentChannel $channel, WebAgentSession $session, array $files, string $caption): string
    {
        if (count($files) === 1) {
            return $this->startDocument($user, $channel, $session, $files[0], $caption);
        }

        $read = [];
        $failed = [];
        foreach (array_slice($files, 0, DocumentFile::maxFiles()) as $file) {
            $kind = DocumentFile::kindOfMime((string) $file['mime']);
            $contents = $kind !== null ? $this->uploads->read($user, $file) : null;
            if ($contents === null) {
                $failed[] = (string) $file['name'];

                continue;
            }
            $read[] = ['name' => (string) $file['name'], 'kind' => $kind, 'bytes' => $contents];
        }
        $this->uploads->delete($user, $files);
        if ($read === []) {
            return $this->notice($channel, $session, "Couldn't open the files. Send them again.", 'error');
        }

        $channel->status('read_document', 'Reading ' . count($read) . ' files');
        $result = $this->documents->fromFiles(
            $user,
            $read,
            trim($caption) !== '' ? trim($caption) : null,
            'Until then, send CSV / Excel / OFX / QIF files with date and amount columns: those need no AI.',
        );
        if ($failed !== [] && in_array($result['type'], ['confirm', 'ask'], true)) {
            $this->notice($channel, $session, "Couldn't open: " . implode(', ', $failed) . '. The other files were read.', 'warning', 'ok');
        }

        return $this->presentImport($user, $channel, $session, $result);
    }

    /**
     * A message with one document (PDF, DOCX, CSV, XLSX, ODS, JSON, OFX, QIF, HTML). One receipt
     * becomes the usual receipt Confirm card (a PDF is attached to it); many rows become ONE
     * import Confirm card, or first a question about tags that don't exist.
     *
     * @param array{id: string, name: string, mime: string, file_size: int} $file
     */
    public function startDocument(User $user, WebAgentChannel $channel, WebAgentSession $session, array $file, string $caption): string
    {
        $caption = trim($caption);
        $kind = DocumentFile::kindOfMime((string) $file['mime']);
        $name = (string) $file['name'];
        $isPdf = $kind === DocumentFile::PDF;
        $contents = $kind !== null ? $this->uploads->read($user, $file) : null;

        if ($contents === null) {
            $this->uploads->delete($user, [$file]);

            return $this->notice($channel, $session, "Couldn't open {$name}. Send it again.", 'error');
        }

        // A PDF receipt with the amount in the message: no AI, same as a photo with a caption.
        $parsed = $isPdf && $caption !== '' ? $this->quickAdd->parseQuickAdd($caption, $user) : null;
        if (is_array($parsed) && !isset($parsed['error'])) {
            return $this->stageOne($user, $channel, (float) $parsed['amount'], (string) $parsed['at'], $parsed['note'], [], [$file]);
        }

        $channel->status('read_document', 'Reading ' . mb_strimwidth($name, 0, 40, '…'));
        $result = $this->documents->fromFiles(
            $user,
            [['name' => $name, 'kind' => $kind, 'bytes' => $contents]],
            $caption !== '' ? $caption : null,
            $isPdf ? 'Until then, pick an amount below or send it like -350 groceries.' : 'Until then, send the amount like -350 groceries, or a CSV with date and amount columns.',
        );
        // Only a PDF can become an attachment; other documents are read and dropped.
        $keep = $isPdf && $result['type'] === 'single' ? [$file] : [];
        if ($keep === [] && !($isPdf && $result['type'] === 'quota')) {
            $this->uploads->delete($user, [$file]);
        }

        if (in_array($result['type'], ['confirm', 'ask'], true)) {
            return $this->presentImport($user, $channel, $session, $result);
        }

        if ($result['type'] === 'quota') {
            return $isPdf
                ? $this->askPrice($channel, $session, [$file], null, (string) $result['message'])
                : $this->notice($channel, $session, (string) $result['message'], 'error', 'quota_exhausted');
        }

        if ($result['type'] !== 'single') {
            return $this->notice($channel, $session, (string) $result['message'], 'error');
        }

        $analysis = $result['analysis'];
        $at = is_string($analysis['at'] ?? null) ? $analysis['at'] : $user->todayDateString();
        $tagIds = $this->matchTags->ids($user, $analysis['suggested_tag_titles'] ?? []);

        if ($analysis['needs_price'] || $analysis['amount'] === null) {
            if ($keep === []) {
                return $this->notice($channel, $session, "Couldn't find a clear total in {$name}. Send the amount like -350 groceries.", 'error');
            }

            return $this->askPrice($channel, $session, $keep, [
                'at' => $at,
                'note' => $analysis['note'],
                'tag_ids' => $tagIds,
            ], "Couldn't find a clear total in {$name}. Send the amount like -350, or pick a button.");
        }

        return $this->stageOne($user, $channel, (float) $analysis['amount'], $at, $analysis['note'], $tagIds, $keep);
    }

    /**
     * A message with photos (refs from WebAgentUploads). Returns the turn status.
     *
     * @param non-empty-list<array{id: string, name: string, mime: string, file_size: int}> $files
     */
    public function start(User $user, WebAgentChannel $channel, WebAgentSession $session, array $files, string $caption): string
    {
        $caption = trim($caption);
        $parsed = $caption !== '' ? $this->quickAdd->parseQuickAdd($caption, $user) : null;

        // "statement", "виписка", "screenshots of my transactions": a list, not receipts.
        if (!is_array($parsed) && DocumentImport::isListCaption($caption)) {
            return $this->screenshots($user, $channel, $session, $files, $caption);
        }

        if (count($files) > 1) {
            $session->setPending([
                'type' => 'receipt_album',
                'files' => $files,
                'caption_hint' => $caption !== '' ? $caption : null,
                'caption_parsed' => is_array($parsed) && !isset($parsed['error']) ? $parsed : null,
            ]);
            $this->ask($channel, $session, 'Got ' . count($files) . ' photos. Is this one receipt, separate receipts, or screenshots of a transaction list (bank app history)?', [
                self::ALBUM_ONE,
                self::ALBUM_EACH,
                self::ALBUM_LIST,
                self::CANCEL,
            ]);

            return 'ask_user';
        }

        if (is_array($parsed) && isset($parsed['error'])) {
            $this->uploads->delete($user, $files);

            return $this->notice($channel, $session, (string) $parsed['error'], 'error');
        }

        if (is_array($parsed)) {
            return $this->stageOne($user, $channel, (float) $parsed['amount'], (string) $parsed['at'], $parsed['note'], [], $files);
        }

        return $this->analyzeOne($user, $channel, $session, $files[0], $caption !== '' ? $caption : null, $files);
    }

    /**
     * The reply to a receipt question (album choice or missing amount).
     * Null when the reply is not an answer: the photos are dropped and the agent handles the message.
     *
     * @param array<string, mixed> $pending
     */
    public function continue(User $user, WebAgentChannel $channel, WebAgentSession $session, array $pending, string $message): ?string
    {
        $files = WebAgentUploads::refsIn($pending);
        $session->setPending(null);
        $session->patchItems(
            static fn (array $item): bool => ($item['kind'] ?? null) === 'ask' && ($item['status'] ?? null) === 'pending',
            ['status' => 'answered'],
        );

        if (($pending['type'] ?? null) === 'import_tags') {
            return $this->continueImport($user, $channel, $session, $pending, $message);
        }

        if ($files === []) {
            return null;
        }

        $answer = mb_strtolower(trim($message));
        if (preg_match('/^(cancel|скасу|відмін|отмен)/u', $answer)) {
            $this->uploads->delete($user, $files);

            return $this->notice($channel, $session, 'Cancelled.', 'muted', 'cancelled');
        }

        return match ($pending['type'] ?? null) {
            'receipt_album' => $this->continueAlbum($user, $channel, $session, $pending, $files, $answer),
            'receipt_price' => $this->continuePrice($user, $channel, $session, $pending, $files, $message),
            default => null,
        };
    }

    public static function isReceiptPending(mixed $pending): bool
    {
        return is_array($pending) && in_array($pending['type'] ?? null, ['receipt_album', 'receipt_price', 'import_tags'], true);
    }

    /**
     * Reply to "these tags don't exist": create / map / without, or any other edit of the import.
     *
     * @param array<string, mixed> $pending
     */
    private function continueImport(User $user, WebAgentChannel $channel, WebAgentSession $session, array $pending, string $message): ?string
    {
        $draft = is_array($pending['draft'] ?? null) ? $pending['draft'] : null;
        if ($draft === null) {
            return null;
        }
        $channel->status('read_document', 'Updating the import');
        $result = $this->documents->reply($user, $draft, $message);
        if ($result === null) {
            $this->notice($channel, $session, 'The import was dropped. Send the file again to import it.', 'muted', 'ok');

            return null;
        }
        if ($result['type'] === 'cancelled') {
            return $this->notice($channel, $session, 'Import cancelled.', 'muted', 'cancelled');
        }

        return $this->presentImport($user, $channel, $session, $result);
    }

    /**
     * Bank-app screenshots of a transaction list → one import card (one vision call for all).
     *
     * @param list<array<string, mixed>> $files
     */
    private function screenshots(User $user, WebAgentChannel $channel, WebAgentSession $session, array $files, string $caption): string
    {
        $images = [];
        $skipped = 0;
        foreach ($files as $file) {
            $contents = $this->analyze->isVisionMime((string) $file['mime']) ? $this->uploads->read($user, $file) : null;
            if ($contents === null) {
                $skipped++;

                continue;
            }
            $images[] = ['bytes' => $contents, 'mime' => (string) $file['mime']];
        }
        $this->uploads->delete($user, $files);
        if ($images === [] || (string) config('services.xai.api_key') === '') {
            return $this->notice($channel, $session, $images === []
                ? 'Screenshots must be JPEG or PNG to be read. Send them again.'
                : "Screenshots can't be read right now. Export the statement as CSV or PDF from the bank app instead.", 'error');
        }

        $channel->status('read_receipt', 'Reading ' . count($images) . ' screenshot' . (count($images) === 1 ? '' : 's'));
        $result = $this->documents->fromScreenshots(
            $user,
            $images,
            $caption !== '' ? $caption : null,
            'Until then, export the statement as CSV, Excel, OFX or QIF from the bank app: those need no AI.',
        );
        if ($skipped > 0 && in_array($result['type'], ['confirm', 'ask'], true)) {
            $this->notice($channel, $session, "{$skipped} image" . ($skipped === 1 ? ' was' : 's were') . ' not JPEG / PNG and ' . ($skipped === 1 ? 'was' : 'were') . ' skipped.', 'warning', 'ok');
        }

        return $this->presentImport($user, $channel, $session, $result);
    }

    /**
     * DocumentImport result → Confirm card, tags question, or a notice.
     *
     * @param array<string, mixed> $result
     */
    private function presentImport(User $user, WebAgentChannel $channel, WebAgentSession $session, array $result): string
    {
        if ($result['type'] === 'confirm') {
            $channel->stageConfirm('import_transactions', ['payload' => $result['payload'], 'preview' => $result['preview']]);
            $session->setSummary(mb_substr('Import preview: ' . $result['summary'], 0, 500));

            return 'confirm_staged';
        }
        if ($result['type'] === 'ask') {
            $session->setPending(['type' => 'import_tags', 'draft' => $result['draft']]);
            $this->ask($channel, $session, (string) $result['question'], $result['suggestions'] ?? DocumentImport::SUGGESTIONS);

            return 'ask_user';
        }
        if ($result['type'] === 'single') {
            // Only reachable for one file, handled by startDocument; treat as "nothing to import".
            return $this->notice($channel, $session, 'That file looks like one receipt. Send it on its own to save it as one transaction.', 'error');
        }

        return $this->notice(
            $channel,
            $session,
            (string) ($result['message'] ?? 'Nothing to import.'),
            'error',
            $result['type'] === 'quota' ? 'quota_exhausted' : 'error',
        );
    }

    /**
     * @param array<string, mixed> $pending
     * @param list<array<string, mixed>> $files
     */
    private function continueAlbum(User $user, WebAgentChannel $channel, WebAgentSession $session, array $pending, array $files, string $answer): ?string
    {
        $one = $answer === mb_strtolower(self::ALBUM_ONE) || preg_match('/^(one|single|1\b|один|одна|одну|одним|разом)/u', $answer);
        $each = $answer === mb_strtolower(self::ALBUM_EACH) || preg_match('/^(separate|each|split|окрем|кожн|різні)/u', $answer);
        $hint = is_string($pending['caption_hint'] ?? null) ? $pending['caption_hint'] : null;

        if ($one) {
            $files = array_slice($files, 0, TransactionAttachmentRules::MAX_PER_TRANSACTION);
            $parsed = is_array($pending['caption_parsed'] ?? null) ? $pending['caption_parsed'] : null;
            if ($parsed !== null) {
                return $this->stageOne($user, $channel, (float) $parsed['amount'], (string) $parsed['at'], $parsed['note'] ?? null, [], $files);
            }

            return $this->analyzeOne($user, $channel, $session, ReceiptFiles::pickBest($files), $hint, $files);
        }

        if ($each) {
            return $this->analyzeEach($user, $channel, $session, $files, $hint);
        }

        if ($answer === mb_strtolower(self::ALBUM_LIST) || preg_match('/^(list|statement|history|transactions|screenshots?|список|виписк|історі|скрін|скрин|выписк|истори)/u', $answer)) {
            return $this->screenshots($user, $channel, $session, $files, $hint ?? '');
        }

        $this->uploads->delete($user, $files);

        return null;
    }

    /**
     * @param array<string, mixed> $pending
     * @param list<array<string, mixed>> $files
     */
    private function continuePrice(User $user, WebAgentChannel $channel, WebAgentSession $session, array $pending, array $files, string $message): ?string
    {
        $parsed = $this->quickAdd->parseQuickAdd(trim($message), $user);

        if ($parsed === null) {
            $this->uploads->delete($user, $files);

            return null;
        }

        if (isset($parsed['error'])) {
            // Keep the photos and ask again.
            $session->setPending($pending);
            $this->ask($channel, $session, $parsed['error'] . '. Send the amount like -350 groceries.', self::PRICE_SUGGESTIONS);

            return 'ask_user';
        }

        $draft = is_array($pending['draft'] ?? null) ? $pending['draft'] : [];
        $note = $parsed['note'] ?? ($draft['note'] ?? null);

        return $this->stageOne(
            $user,
            $channel,
            (float) $parsed['amount'],
            is_string($draft['at'] ?? null) ? $draft['at'] : (string) $parsed['at'],
            is_string($note) ? $note : null,
            is_array($draft['tag_ids'] ?? null) ? $draft['tag_ids'] : [],
            $files,
        );
    }

    /**
     * Vision on one photo; every photo in $allFiles is attached.
     *
     * @param array<string, mixed> $file
     * @param list<array<string, mixed>> $allFiles
     */
    private function analyzeOne(User $user, WebAgentChannel $channel, WebAgentSession $session, array $file, ?string $hint, array $allFiles): string
    {
        if (!$this->canUseVision($user, (string) $file['mime'])) {
            $question = !$this->analyze->hasQuota($user)
                ? $this->analyze->quota()->exhaustedMessage($user, 'Until then, pick an amount below or send it like -350 groceries.')
                : 'Receipt received. Pick an amount or send like -350 groceries';

            return $this->askPrice($channel, $session, $allFiles, null, $question);
        }

        $channel->status('read_receipt', 'Reading receipt');
        $analysis = $this->read($user, $file, $hint);

        if ($analysis === null) {
            return $this->askPrice($channel, $session, $allFiles, null, "Couldn't read that receipt automatically. Pick an amount or send like -350 groceries");
        }

        $this->analyze->consumeQuota($user);

        // One screenshot of a whole transaction list: its rows become an import (no extra assist).
        if (count($allFiles) === 1 && is_array($analysis['transaction_list'] ?? null)) {
            $this->uploads->delete($user, $allFiles);

            return $this->presentImport($user, $channel, $session, $this->documents->fromScreenshotRows($user, [$analysis['transaction_list']], $hint));
        }

        $at = is_string($analysis['at'] ?? null) ? $analysis['at'] : $user->todayDateString();
        $tagIds = $this->matchTags->ids($user, $analysis['suggested_tag_titles'] ?? []);

        if ($analysis['needs_price'] || $analysis['amount'] === null) {
            return $this->askPrice($channel, $session, $allFiles, [
                'at' => $at,
                'note' => $analysis['note'],
                'tag_ids' => $tagIds,
            ], "Couldn't read a clear total (e.g. food photo). Send the amount like -350, or pick a button.");
        }

        return $this->stageOne($user, $channel, (float) $analysis['amount'], $at, $analysis['note'], $tagIds, $allFiles);
    }

    /**
     * "Separate": vision on every photo, one staged transaction each. Photos without a readable
     * total (or past the quota) are skipped, and a notice before the Confirm card names them
     * ("Photo 2 couldn't be read and was skipped").
     *
     * @param list<array<string, mixed>> $files
     */
    private function analyzeEach(User $user, WebAgentChannel $channel, WebAgentSession $session, array $files, ?string $hint): string
    {
        $rows = [];
        $skipped = [];
        $total = count($files);

        foreach ($files as $i => $file) {
            $n = $i + 1;
            if (!$this->canUseVision($user, (string) $file['mime'])) {
                $skipped[$n] = $this->analyze->hasQuota($user) ? 'could not be read' : 'no AI assists left';
                $this->uploads->delete($user, [$file]);

                continue;
            }

            $channel->status('read_receipt', "Reading receipt {$n} of {$total}");
            $analysis = $this->read($user, $file, $hint);
            if ($analysis === null) {
                $skipped[$n] = 'could not be read';
                $this->uploads->delete($user, [$file]);

                continue;
            }

            $this->analyze->consumeQuota($user);

            if ($analysis['needs_price'] || $analysis['amount'] === null) {
                $skipped[$n] = 'no clear total';
                $this->uploads->delete($user, [$file]);

                continue;
            }

            $prepared = $this->tools->prepareCreateTransaction($user, [
                'amount' => (float) $analysis['amount'],
                'at' => is_string($analysis['at'] ?? null) ? $analysis['at'] : $user->todayDateString(),
                'note' => $analysis['note'],
                'tag_ids' => $this->matchTags->ids($user, $analysis['suggested_tag_titles'] ?? []),
            ]);
            $rows[$n] = $prepared['payload'] + ['files' => [$file]];
        }

        if ($rows === []) {
            $message = self::skippedText($skipped) . ' ' . ($this->analyze->hasQuota($user)
                ? 'Send them one by one with the amount, like -350 groceries.'
                : $this->analyze->quota()->exhaustedMessage($user, 'Until then, send each photo with the amount in the message, like -350 groceries.'));

            return $this->notice($channel, $session, $message, 'error');
        }

        // Skipped photos are said in the reply (not only on the card), before the Confirm card.
        if ($skipped !== []) {
            $channel->push('notice', [
                'role' => 'assistant',
                'kind' => 'notice',
                'tone' => 'warning',
                'text' => self::skippedText($skipped) . ' Send ' . (count($skipped) === 1 ? 'it' : 'them')
                    . ' again with the amount, like -350 groceries.',
            ]);
        }

        if (count($rows) === 1) {
            $row = array_values($rows)[0];

            return $this->stageOne($user, $channel, (float) $row['amount'], (string) $row['at'], $row['note'], $row['tag_ids'], $row['files']);
        }

        $lines = [];
        foreach ($rows as $n => $row) {
            $lines[] = $this->previewLine($user, $row, "Photo {$n}");
        }

        $channel->stageConfirm('create_transactions', [
            'payload' => ['transactions' => array_values($rows)],
            'preview' => '🧾 ' . count($rows) . " receipts:\n" . implode("\n", $lines),
        ]);

        return 'confirm_staged';
    }

    /**
     * "Photo 2 couldn't be read and was skipped. Photos 3 and 4 have no clear total and were skipped."
     *
     * @param array<int, string> $skipped photo number => reason
     */
    public static function skippedText(array $skipped): string
    {
        $byReason = [];
        foreach ($skipped as $n => $why) {
            $byReason[$why][] = (int) $n;
        }

        $sentences = [];
        foreach ($byReason as $why => $numbers) {
            $one = count($numbers) === 1;
            $last = array_pop($numbers);
            $label = ($one ? 'Photo ' : 'Photos ') . ($numbers === [] ? $last : implode(', ', $numbers) . ' and ' . $last);
            $sentences[] = $label . match ($why) {
                'no clear total' => $one ? ' has no clear total and was skipped.' : ' have no clear total and were skipped.',
                'no AI assists left' => ($one ? ' was' : ' were') . ' skipped: no AI assists left today.',
                default => $one ? " couldn't be read and was skipped." : " couldn't be read and were skipped.",
            };
        }

        return implode(' ', $sentences);
    }

    /**
     * @param list<int> $tagIds
     * @param list<array<string, mixed>> $files
     */
    private function stageOne(User $user, WebAgentChannel $channel, float $amount, string $at, mixed $note, array $tagIds, array $files): string
    {
        $prepared = $this->tools->prepareCreateTransaction($user, [
            'amount' => $amount,
            'at' => $at,
            'note' => is_string($note) ? $note : null,
            'tag_ids' => $tagIds,
        ]);
        $prepared['payload']['files'] = array_values($files);
        $prepared['preview'] = '🧾 Receipt' . "\n"
            . preg_replace('/^Create transaction:\n/', '', $prepared['preview'])
            . ($files !== [] ? "\n📎 " . $this->photoCount($files) : '');

        $channel->stageConfirm('create_transaction', $prepared);

        return 'confirm_staged';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function previewLine(User $user, array $row, string $label): string
    {
        $tags = $row['tag_ids'] !== [] ? ' · ' . $this->tools->tagLabels($user, $row['tag_ids']) : '';
        $note = is_string($row['note'] ?? null) && $row['note'] !== '' ? ' · ' . $row['note'] : '';

        return '• ' . $label . ': ' . UserFormatter::formatMoney($user, (float) $row['amount']) . ' on ' . $row['at'] . $note . $tags;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param array<string, mixed>|null $draft
     */
    private function askPrice(WebAgentChannel $channel, WebAgentSession $session, array $files, ?array $draft, string $question): string
    {
        $session->setPending([
            'type' => 'receipt_price',
            'files' => array_values($files),
            'draft' => $draft,
        ]);
        $this->ask($channel, $session, $question, self::PRICE_SUGGESTIONS);

        return 'ask_user';
    }

    /**
     * @param list<string> $suggestions
     */
    private function ask(WebAgentChannel $channel, WebAgentSession $session, string $question, array $suggestions): void
    {
        $channel->push('ask', [
            'role' => 'assistant',
            'kind' => 'ask',
            'question' => $question,
            'suggestions' => $suggestions,
            'status' => 'pending',
        ]);
        $session->setSummary(mb_substr('Receipt: ' . $question, 0, 500));
    }

    private function notice(WebAgentChannel $channel, WebAgentSession $session, string $text, string $tone, string $status = 'error'): string
    {
        $channel->push('notice', ['role' => 'assistant', 'kind' => 'notice', 'tone' => $tone, 'text' => $text]);
        $session->setSummary(mb_substr('Receipt: ' . $text, 0, 500));

        return $status;
    }

    private function canUseVision(User $user, string $mime): bool
    {
        return $this->analyze->isVisionMime($mime)
            && $this->analyze->hasQuota($user)
            && (string) config('services.xai.api_key') !== '';
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>|null
     */
    private function read(User $user, array $file, ?string $hint): ?array
    {
        $contents = $this->uploads->read($user, $file);

        return $contents === null ? null : $this->analyze->execute($user, $contents, (string) $file['mime'], $hint);
    }

    /**
     * "2 photos", or "1 file: invoice.pdf" for a document.
     *
     * @param list<array<string, mixed>> $files
     */
    private function photoCount(array $files): string
    {
        $n = count($files);
        if ($n === 1 && WebAgentUploads::isDocumentRef($files[0])) {
            return '1 file: ' . (string) ($files[0]['name'] ?? 'document');
        }

        return $n . ' photo' . ($n === 1 ? '' : 's');
    }
}
