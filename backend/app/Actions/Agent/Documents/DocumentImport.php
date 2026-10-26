<?php

namespace App\Actions\Agent\Documents;

use App\Actions\Tags\ListTagsAction;
use App\Models\User;
use App\Support\TelegramAiQuota;

/**
 * One bulk import from up to DocumentFile::maxFiles() documents (or a set of bank-app screenshots)
 * plus what the user wrote with them, shared by the web drawer and Telegram:
 *  1. every file is read into rows (ReadDocumentAction), then merged; a row that shows up in
 *     several files / overlapping screenshots is kept once (max count per date + amount + note);
 *  2. the caption becomes plan edits (ImportInstructions: phrases without AI, else one AI call);
 *  3. ImportPlan builds the Confirm card, or asks what to do with tags that don't exist. The
 *     user's free-text reply edits the same draft (reply()) and a new card is shown.
 *
 * Result: error | quota | single (one file that is one receipt) | confirm | ask (+ draft to keep
 * in the pending slot) | cancelled.
 */
final class DocumentImport
{
    /** Replies to the tags question before unknown tags are just left out. */
    public const MAX_ROUNDS = 3;

    /** Screenshots read in one vision call. */
    public const MAX_SCREENSHOTS = 10;

    /** Caption words that mean "these screenshots are a list of transactions". */
    public const LIST_WORDS = '/\b(bank statements?|statements?|transactions?( list)?|transaction history|history|screenshots?|operations|виписк\w*|список\w*|історі\w*|транзакц\w*|операці\w*|скріни?\w*|скрини?\w*|выписк\w*|истори\w*|операци\w*)\b/iu';

    public const SUGGESTIONS = ['Create them', 'Import without tags', 'Cancel'];

    public function __construct(
        private readonly ReadDocumentAction $read,
        private readonly ImportPlan $plan,
        private readonly DocumentAi $ai,
        private readonly TelegramAiQuota $quota,
        private readonly ListTagsAction $listTags,
    ) {}

    public static function isListCaption(?string $caption): bool
    {
        return $caption !== null && preg_match(self::LIST_WORDS, $caption) === 1;
    }

    /**
     * @param list<array{name: string, kind: string, bytes: string}> $files
     * @return array<string, mixed>
     */
    public function fromFiles(User $user, array $files, ?string $caption, string $withoutAi): array
    {
        $files = array_slice($files, 0, DocumentFile::maxFiles());
        $single = count($files) === 1;
        $parts = [];
        $notes = [];
        $errors = [];
        $quotaMessage = null;
        $aiUsed = false;

        foreach ($files as $file) {
            $result = $this->read->execute($user, $file['kind'], $file['bytes'], $file['name'], $caption, $withoutAi);
            if ($single && $result['type'] !== 'rows') {
                return $result;
            }
            if ($result['type'] === 'single') {
                // Several files: a receipt among statements is one more row (its file is not attached).
                $aiUsed = true;
                $analysis = $result['analysis'];
                if ($analysis['amount'] === null || $analysis['needs_price']) {
                    $notes[] = "⚠️ {$file['name']}: no total found, left out";

                    continue;
                }
                $notes[] = "ℹ️ {$file['name']} was read as one receipt (the file itself is not attached)";
                $result = [
                    'type' => 'rows',
                    'file' => ['name' => mb_substr($file['name'], 0, 120), 'kind' => $file['kind'], 'ambiguous' => false, 'order' => null, 'samples' => []],
                    'rows' => [[
                        'line' => 1,
                        'at' => $analysis['at'] ?? $user->todayDateString(),
                        'amount' => round((float) $analysis['amount'], 2),
                        'note' => $analysis['note'],
                        'tags' => $analysis['suggested_tag_titles'],
                        'soft' => true,
                    ]],
                    'skipped' => [],
                    'capped' => false,
                    'ai_used' => true,
                ];
            }
            if ($result['type'] === 'rows') {
                $parts[] = $result;
                $aiUsed = $aiUsed || $result['ai_used'];

                continue;
            }
            if ($result['type'] === 'quota') {
                $quotaMessage = (string) $result['message'];
                $errors[] = "{$file['name']}: needs an AI assist and none are left today";
            } else {
                $errors[] = "{$file['name']}: " . $result['message'];
            }
        }

        if ($parts === []) {
            return $quotaMessage !== null && count($errors) === 1
                ? ['type' => 'quota', 'message' => $quotaMessage]
                : ['type' => 'error', 'message' => "Nothing to import:\n" . implode("\n", array_map(static fn ($e) => '• ' . $e, $errors))];
        }
        foreach ($errors as $error) {
            $notes[] = '⚠️ Not imported: ' . $error;
        }

        $draft = $this->merge($parts, $notes, false);
        $draft['ai_used'] = $aiUsed;

        return $this->withCaption($user, $draft, $caption);
    }

    /**
     * Bank-app screenshots of a transaction list: one vision call for all of them (1 assist).
     *
     * @param list<array{bytes: string, mime: string}> $images
     * @return array<string, mixed>
     */
    public function fromScreenshots(User $user, array $images, ?string $caption, string $withoutAi): array
    {
        $images = array_slice($images, 0, max(1, (int) config('agent.documents.max_screenshots', self::MAX_SCREENSHOTS)));
        if ($images === []) {
            return ['type' => 'error', 'message' => 'No screenshots to read (JPEG or PNG).'];
        }
        if (!$this->quota->hasQuota($user)) {
            return ['type' => 'quota', 'message' => $this->quota->exhaustedMessage($user, $withoutAi)];
        }
        $perImage = $this->ai->readScreenshots($user, $images, $caption);
        if ($perImage === null) {
            return ['type' => 'error', 'message' => "Couldn't read the screenshots right now. Try again later, or export the statement as CSV / PDF from the bank app."];
        }
        $this->quota->consume($user);

        return $this->fromScreenshotRows($user, $perImage, $caption);
    }

    /**
     * Rows already read from screenshots (e.g. one photo the receipt reader saw was a list).
     *
     * @param list<list<array{amount: float, at: ?string, note: ?string, suggested_tag_titles: list<string>}>> $perImage
     * @return array<string, mixed>
     */
    public function fromScreenshotRows(User $user, array $perImage, ?string $caption): array
    {
        $parts = [];
        foreach ($perImage as $i => $items) {
            $rows = [];
            $skipped = [];
            foreach ($items as $j => $item) {
                if ($item['at'] === null) {
                    $skipped[] = ['line' => $j + 1, 'reason' => 'no date visible'];

                    continue;
                }
                $rows[] = ['line' => $j + 1, 'at' => $item['at'], 'amount' => $item['amount'], 'note' => $item['note'], 'tags' => $item['suggested_tag_titles'], 'soft' => true];
            }
            $parts[] = [
                'file' => ['name' => 'screenshot ' . ($i + 1), 'kind' => 'image', 'ambiguous' => false, 'order' => null, 'samples' => []],
                'rows' => $rows,
                'skipped' => $skipped,
                'capped' => false,
            ];
        }
        if (array_sum(array_map(static fn (array $p) => count($p['rows']), $parts)) === 0) {
            return ['type' => 'error', 'message' => count($perImage) === 1
                ? 'No dated transactions found on this screenshot.'
                : 'No dated transactions found on these screenshots.'];
        }

        $draft = $this->merge($parts, [], true);
        $draft['ai_used'] = true;

        return $this->withCaption($user, $draft, $caption);
    }

    /**
     * The user's reply to the tags question (or another edit of the pending import). Null when the
     * message isn't about the import: the caller then treats it as a normal message.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>|null
     */
    public function reply(User $user, array $draft, string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(cancel|stop|no|nope|скасуй\w*|скасувати|відміни\w*|відмін\w*|ні|не треба|отмен\w*|нет|не надо)[.!]?$/iu', $text)) {
            return ['type' => 'cancelled'];
        }

        $draft['rounds'] = (int) ($draft['rounds'] ?? 0) + 1;
        $read = $this->interpret($user, $draft, $text, true);
        if (!$read['understood']) {
            if ($read['note'] !== null) {
                return [
                    'type' => 'ask',
                    'question' => $read['note'] . "\nReply “create them”, “without tags”, or map one, e.g. “"
                        . (($draft['unknown'][0] ?? 'Cafe')) . ' → Food”.',
                    'draft' => $draft,
                    'suggestions' => self::SUGGESTIONS,
                ];
            }

            return null;
        }
        $draft = $read['draft'];
        unset($draft['unknown']);

        return $this->plan($user, $draft);
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function withCaption(User $user, array $draft, ?string $caption): array
    {
        $caption = trim((string) $caption);
        if ($caption !== '') {
            $read = $this->interpret($user, $draft, $caption, false);
            $draft = $read['draft'];
            if ($read['note'] !== null) {
                $draft['notes'][] = $read['note'];
            }
        }

        return $this->plan($user, $draft);
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function plan(User $user, array $draft): array
    {
        $built = $this->plan->build($user, $draft);
        if (isset($built['ask']) && (int) ($draft['rounds'] ?? 0) >= self::MAX_ROUNDS) {
            $draft['ops']['unknown_tags'] = 'none';
            $built = $this->plan->build($user, $draft);
        }
        if (isset($built['error'])) {
            return ['type' => 'error', 'message' => $built['error']];
        }
        if (isset($built['ask'])) {
            $draft['unknown'] = $built['unknown'];

            return ['type' => 'ask', 'question' => $built['ask'], 'draft' => $draft, 'suggestions' => self::SUGGESTIONS];
        }

        return ['type' => 'confirm'] + $built;
    }

    /**
     * Words → plan edits: known phrases without AI; anything else through one AI call (free when
     * this import already used an assist, else it costs one).
     *
     * @param array<string, mixed> $draft
     * @return array{draft: array<string, mixed>, understood: bool, note: ?string}
     */
    private function interpret(User $user, array $draft, string $text, bool $reply): array
    {
        $existing = $this->existingTitles($user);
        $unknown = is_array($draft['unknown'] ?? null) ? $draft['unknown'] : $this->unknownTitles($draft, $existing);
        $stripped = trim((string) preg_replace(self::LIST_WORDS, ' ', $text));
        $quick = ImportInstructions::quick($stripped, $unknown, $existing, $reply);
        $ops = $quick['ops'];
        $note = null;

        $words = preg_match_all('/[\p{L}\p{N}]{2,}/u', $stripped);
        // A caption of a word or two ("October", "Monobank") is a label, not an instruction.
        $worthAi = !$quick['complete'] && ($reply ? $words > 0 : $words > 2);
        if ($worthAi) {
            $free = (bool) ($draft['ai_used'] ?? false);
            if ($free || $this->quota->hasQuota($user)) {
                $json = $this->ai->instructions($user, $text, [
                    'tags' => array_values($existing),
                    'unknown' => $unknown,
                    'files' => $this->fileContext($draft),
                    'notes' => $this->sampleNotes($draft),
                    'date_samples' => $this->dateSamples($draft),
                    'reply' => $reply,
                ]);
                if ($json !== null) {
                    if (!$free) {
                        $this->quota->consume($user);
                        $draft['ai_used'] = true;
                    }
                    $ops = ImportInstructions::merge($ops, ImportInstructions::normalize($json));
                } else {
                    $note = $ops === [] ? "⚠️ Couldn't read your message right now, so it wasn't applied." : '⚠️ Your message was only partly applied (the AI is not available right now).';
                }
            } else {
                $note = $ops === []
                    ? '⚠️ Your message needs an AI assist to apply and none are left today, so it wasn\'t applied.'
                    : '⚠️ Your message was only partly applied: the rest needs an AI assist and none are left today.';
            }
        }

        if ($ops !== []) {
            $draft['ops'] = ImportInstructions::merge(is_array($draft['ops'] ?? null) ? $draft['ops'] : [], $ops);
        }

        return ['draft' => $draft, 'understood' => $ops !== [], 'note' => $note];
    }

    /**
     * @param list<array<string, mixed>> $parts ReadDocumentAction rows results (or screenshot parts)
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private function merge(array $parts, array $notes, bool $screenshots): array
    {
        $files = [];
        $rows = [];
        $skipped = [];
        $capped = false;
        $kept = [];
        $overlap = 0;
        $max = DocumentFile::maxRows();

        foreach ($parts as $f => $part) {
            $files[] = $part['file'];
            $capped = $capped || $part['capped'];
            $local = [];
            foreach ($part['rows'] as $row) {
                $key = ImportPlan::key($row['at'], (float) $row['amount'], $row['note'] ?? null);
                $local[$key] = ($local[$key] ?? 0) + 1;
                if ($local[$key] <= ($kept[$key] ?? 0)) {
                    $overlap++;

                    continue;
                }
                if (count($rows) >= $max) {
                    $capped = true;

                    continue;
                }
                $row['f'] = $f;
                if ($part['file']['ambiguous'] ?? false) {
                    $row['order'] = $part['file']['order'];
                }
                $rows[] = $row;
            }
            foreach ($local as $key => $n) {
                $kept[$key] = max($kept[$key] ?? 0, $n);
            }
            foreach ($part['skipped'] as $skip) {
                $skipped[] = ['f' => $f, 'line' => $skip['line'], 'reason' => $skip['reason']];
            }
        }

        $names = array_map(static fn (array $file) => $file['name'], $files);
        $source = count($files) === 1
            ? ($screenshots ? 'a screenshot' : $names[0])
            : ($screenshots
                ? count($files) . ' screenshots'
                : count($files) . ' files (' . implode(', ', array_map(static fn ($n) => mb_strimwidth($n, 0, 30, '…'), $names)) . ')');

        return [
            'v' => 1,
            'source' => mb_substr($source, 0, 200),
            'screenshots' => $screenshots,
            'files' => $files,
            'rows' => $rows,
            'skipped' => $skipped,
            'capped' => $capped,
            'overlap' => $overlap,
            'notes' => $notes,
            'ops' => [],
            'rounds' => 0,
        ];
    }

    /**
     * @return array<string, string> lower title => title
     */
    private function existingTitles(User $user): array
    {
        $titles = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $titles[mb_strtolower(trim((string) $tag->title))] ??= trim((string) $tag->title);
        }

        return $titles;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, string> $existing
     * @return list<string>
     */
    private function unknownTitles(array $draft, array $existing): array
    {
        $unknown = [];
        foreach ($draft['rows'] ?? [] as $row) {
            if ($row['soft'] ?? false) {
                continue;
            }
            foreach ($row['tags'] ?? [] as $title) {
                $lower = mb_strtolower(trim((string) $title));
                if ($lower !== '' && !isset($existing[$lower])) {
                    $unknown[$lower] ??= trim((string) $title);
                }
            }
        }

        return array_values(array_slice($unknown, 0, 100));
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<array{name: string, rows: int, lines: string}>
     */
    private function fileContext(array $draft): array
    {
        $out = [];
        foreach ($draft['files'] ?? [] as $f => $file) {
            $lines = array_map(static fn (array $row) => (int) $row['line'], array_filter($draft['rows'], static fn (array $row) => $row['f'] === $f));
            $out[] = ['name' => (string) $file['name'], 'rows' => count($lines), 'lines' => $lines !== [] ? min($lines) . '-' . max($lines) : ''];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<string>
     */
    private function sampleNotes(array $draft): array
    {
        $notes = [];
        foreach ($draft['rows'] ?? [] as $row) {
            if (is_string($row['note'] ?? null) && $row['note'] !== '') {
                $notes[mb_strtolower($row['note'])] = mb_substr($row['note'], 0, 60);
            }
            if (count($notes) >= 25) {
                break;
            }
        }

        return array_values($notes);
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<string>
     */
    private function dateSamples(array $draft): array
    {
        $samples = [];
        foreach ($draft['files'] ?? [] as $file) {
            if ($file['ambiguous'] ?? false) {
                array_push($samples, ...array_slice($file['samples'] ?? [], 0, 6));
            }
        }

        return array_slice($samples, 0, 12);
    }
}
