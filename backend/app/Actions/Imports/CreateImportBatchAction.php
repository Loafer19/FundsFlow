<?php

namespace App\Actions\Imports;

use App\Actions\Agent\Documents\ImportInstructions;
use App\Actions\Agent\Documents\ImportPlan;
use App\Actions\Tags\ListTagsAction;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\MonobankTagRule;
use App\Models\User;
use App\Services\Monobank\MerchantNormalizer;
use App\Services\Monobank\TransferDetector;
use App\Support\UserFormatter;

class CreateImportBatchAction
{
    public function __construct(
        private readonly ListTagsAction $listTags,
        private readonly BankCategoryResolver $categories,
        private readonly ImportTypeAssigner $types,
    ) {}

    /** @param  array<string, mixed>  $draft */
    public function fromDraft(User $user, array $draft, string $channel = 'assistant'): ImportBatch
    {
        $files = is_array($draft['files'] ?? null) ? $draft['files'] : [];
        $first = $files[0] ?? [];
        $headerSignature = $this->headerSignature($first);

        $batch = $user->importBatches()->create([
            'source' => $channel,
            'status' => ImportBatch::STATUS_PREVIEWING,
            'filename' => mb_substr((string) ($first['name'] ?? $draft['source'] ?? 'file'), 0, 255),
            'format' => isset($first['kind']) ? (string) $first['kind'] : null,
            'header_signature' => $headerSignature,
            'mapping' => is_array($first['mapping'] ?? null) ? $first['mapping'] : null,
            'options' => ['include_foreign' => false, 'skip_transfers' => true],
            'draft' => $draft,
            'notes' => array_values(array_filter($draft['notes'] ?? [], 'is_string')),
            'ai_used' => (bool) ($draft['ai_used'] ?? false),
        ]);

        $this->seedCategoryMap($user, $batch);
        $this->rebuildRows($user, $batch);
        $this->persistTemplate($user, $batch);

        return $batch->fresh('rows');
    }

    public function seedCategoryMap(User $user, ImportBatch $batch): void
    {
        $counts = $this->categoryCounts($batch->draft ?? []);
        if ($counts === []) {
            $batch->category_map = [];
            $batch->save();

            return;
        }

        $tagsByTitle = [];
        $owned = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $tagsByTitle[mb_strtolower(trim((string) $tag->title))] ??= (int) $tag->id;
            $owned[(int) $tag->id] = true;
        }

        $saved = [];
        if ($batch->header_signature) {
            $template = $user->importTemplates()->where('header_signature', $batch->header_signature)->first();
            $saved = is_array($template?->category_map) ? $template->category_map : [];
        }

        $map = is_array($batch->category_map) ? $batch->category_map : [];
        foreach (array_keys($counts) as $name) {
            if ($this->categories->findDecision($map, $name) !== null) {
                continue;
            }
            $fromTemplate = $this->categories->findDecision($saved, $name);
            $normalized = $fromTemplate
                ? $this->categories->normalizeDecision($fromTemplate, $owned, $name)
                : null;
            $map[$name] = $normalized ?? $this->categories->defaultDecision($name, $tagsByTitle);
        }

        $batch->category_map = $map;
        $batch->save();
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public function applyCategoryDecision(User $user, ImportBatch $batch, string $name, array $decision): void
    {
        $owned = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $owned[(int) $tag->id] = true;
        }
        $normalized = $this->categories->normalizeDecision($decision, $owned, $name);
        if ($normalized === null) {
            return;
        }
        $map = is_array($batch->category_map) ? $batch->category_map : [];
        $map[$this->categories->canonicalName($map, $name)] = $normalized;
        $batch->category_map = $map;
        $batch->save();
        $this->persistTemplate($user, $batch);
    }

    public function persistTemplate(User $user, ImportBatch $batch): void
    {
        if (!$batch->header_signature || !is_array($batch->mapping) || $batch->mapping === []) {
            return;
        }
        ImportTemplate::query()->updateOrCreate(
            ['user_id' => $user->id, 'header_signature' => $batch->header_signature],
            [
                'name' => $batch->filename,
                'mapping' => $batch->mapping,
                'category_map' => $batch->category_map ?? [],
            ],
        );
    }

    public function rebuildRows(User $user, ImportBatch $batch): void
    {
        $draft = $batch->draft ?? [];
        $ops = is_array($draft['ops'] ?? null) ? $draft['ops'] : [];
        $titles = [];
        $tagsByTitle = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $lower = mb_strtolower(trim((string) $tag->title));
            $titles[$lower] ??= trim((string) $tag->title);
            $tagsByTitle[$lower] ??= (int) $tag->id;
        }

        $originalCategory = [];
        foreach ($draft['rows'] ?? [] as $row) {
            $line = (int) ($row['line'] ?? 0);
            if ($line > 0) {
                $originalCategory[$line] = $this->rowCategory($row);
            }
        }

        $edited = ImportInstructions::apply($user, $draft['rows'] ?? [], $ops, $titles, count($draft['files'] ?? []));
        $rows = $edited['rows'];
        $includeForeign = (bool) ($batch->options['include_foreign'] ?? false);
        $skipTransfers = ($batch->options['skip_transfers'] ?? true) !== false;
        $skipBefore = is_string($batch->options['skip_before'] ?? null) ? $batch->options['skip_before'] : null;
        $merchantMaps = is_array($batch->options['merchant_map'] ?? null) ? $batch->options['merchant_map'] : [];
        $skipNames = array_map('mb_strtolower', is_array($batch->options['skip_names'] ?? null) ? $batch->options['skip_names'] : []);

        $ownIbans = [];
        $jarTitles = [];
        $connection = $user->monobankConnection?->load('accounts');
        if ($connection) {
            $ownIbans = $connection->accounts->pluck('iban')->filter()
                ->map(fn ($iban) => strtoupper(preg_replace('/\s+/', '', (string) $iban) ?? ''))
                ->values()->all();
            $jarTitles = $connection->accounts->where('type', 'jar')->pluck('title')->filter()->values()->all();
        }

        $existing = $this->existingKeys($user, $rows);
        $merchantRules = MonobankTagRule::query()->where('user_id', $user->id)->get()->keyBy('merchant_key');
        $categoryMap = is_array($batch->category_map) ? $batch->category_map : [];

        $manuals = [];
        foreach ($batch->rows as $saved) {
            if ($saved->manual) {
                $manuals[(int) $saved->line] = [
                    'tag_ids' => $saved->tag_ids ?? [],
                    'status' => $saved->status,
                    'skip_reason' => $saved->skip_reason,
                ];
            }
        }

        $batch->rows()->delete();
        $now = now();
        $insert = [];
        foreach ($rows as $i => $row) {
            $classified = $this->classifyRow(
                $row,
                $i,
                $batch->id,
                $existing,
                $tagsByTitle,
                $merchantRules,
                $merchantMaps,
                $skipNames,
                $ownIbans,
                $jarTitles,
                $includeForeign,
                $skipTransfers,
                $skipBefore,
                $categoryMap,
                $originalCategory,
                $now,
            );
            $line = $classified['line'];
            if (isset($manuals[$line])) {
                $manual = $manuals[$line];
                $classified['manual'] = true;
                $classified['tag_ids'] = json_encode(array_values($manual['tag_ids']));
                $classified['new_tag_titles'] = json_encode([]);
                if ($classified['status'] !== ImportRow::STATUS_ERROR) {
                    $classified['status'] = $manual['status'];
                    $classified['skip_reason'] = $manual['skip_reason'];
                }
            }
            $insert[] = $classified;
        }
        foreach (array_chunk($insert, 250) as $chunk) {
            ImportRow::query()->insert($chunk);
        }

        $notes = array_values(array_filter($draft['notes'] ?? [], 'is_string'));
        foreach ($edited['applied'] ?? [] as $line) {
            $notes[] = $line;
        }
        $batch->notes = array_values(array_unique($notes));
        $this->types->assign($user, $batch);
        $this->recalculate($batch);
    }

    public function recalculate(ImportBatch $batch): void
    {
        $rows = $batch->rows()->get();
        $income = 0.0;
        $expense = 0.0;
        $dates = [];
        $counts = ['new' => 0, 'duplicate' => 0, 'transfer' => 0, 'skipped' => 0, 'needs_tag' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + 1;
            if ($row->isImportable()) {
                $amount = (float) $row->amount;
                $amount > 0 ? $income += $amount : $expense += $amount;
            }
            if ($row->at) {
                $dates[] = $row->at->toDateString();
            }
        }
        sort($dates);
        $batch->forceFill([
            'total_rows' => $rows->count(),
            'new_count' => $counts['new'] ?? 0,
            'duplicate_count' => $counts['duplicate'] ?? 0,
            'transfer_count' => $counts['transfer'] ?? 0,
            'skipped_count' => $counts['skipped'] ?? 0,
            'needs_tag_count' => $counts['needs_tag'] ?? 0,
            'error_count' => $counts['error'] ?? 0,
            'income_total' => round($income, 2),
            'expense_total' => round($expense, 2),
            'period_from' => $dates[0] ?? null,
            'period_to' => $dates ? $dates[count($dates) - 1] : null,
        ])->save();
    }

    public function summaryLine(User $user, ImportBatch $batch): string
    {
        $range = '';
        if ($batch->period_from && $batch->period_to) {
            $from = UserFormatter::formatDate($user, $batch->period_from->toDateString());
            $to = UserFormatter::formatDate($user, $batch->period_to->toDateString());
            $range = ' · ' . $from . ($from !== $to ? ' – ' . $to : '');
        }

        return $batch->total_rows . ' rows' . $range . ' · ' . $batch->new_count . ' new'
            . ($batch->duplicate_count ? ', ' . $batch->duplicate_count . ' duplicates' : '')
            . ($batch->transfer_count ? ', ' . $batch->transfer_count . ' transfers' : '')
            . ($batch->skipped_count ? ', ' . $batch->skipped_count . ' skipped' : '')
            . ($batch->needs_tag_count ? ', ' . $batch->needs_tag_count . ' need a tag' : '');
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, int>
     */
    public function categoryCounts(array $draft): array
    {
        $counts = [];
        foreach ($draft['rows'] ?? [] as $row) {
            foreach ($row['tags'] ?? [] as $title) {
                $name = trim((string) $title);
                if ($name === '') {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function classifyRow(
        $row,
        $index,
        $batchId,
        $existing,
        $tagsByTitle,
        $merchantRules,
        $merchantMaps,
        $skipNames,
        $ownIbans,
        $jarTitles,
        $includeForeign,
        $skipTransfers,
        $skipBefore,
        $categoryMap,
        $originalCategory,
        $now,
    ): array {
        $note = is_string($row['note'] ?? null) ? mb_substr(trim($row['note']), 0, 255) : null;
        $amount = isset($row['amount']) && is_numeric($row['amount']) ? round((float) $row['amount'], 2) : null;
        $at = is_string($row['at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['at']) ? $row['at'] : null;
        $currency = $row['currency'] ?? $row['cur'] ?? null;
        $currency = $currency !== null ? strtoupper(trim((string) $currency)) : null;
        if ($currency === '') {
            $currency = null;
        }

        $line = (int) ($row['line'] ?? ($index + 1));
        $category = $originalCategory[$line] ?? $this->rowCategory($row);

        $tagIds = [];
        $newTitles = [];

        $merchantKey = MerchantNormalizer::normalize($note);
        $hay = $merchantKey ? mb_strtolower($merchantKey) : '';
        $mappedTitle = null;
        if ($hay !== '') {
            foreach ($merchantMaps as $needle => $title) {
                if ($needle !== '' && str_contains($hay, (string) $needle)) {
                    $mappedTitle = $title;
                    break;
                }
            }
        }
        if ($mappedTitle && isset($tagsByTitle[mb_strtolower((string) $mappedTitle)])) {
            $id = $tagsByTitle[mb_strtolower((string) $mappedTitle)];
            $tagIds[$id] = $id;
        } elseif ($merchantKey && $merchantRules->has($merchantKey)) {
            $id = (int) $merchantRules->get($merchantKey)->tag_id;
            $tagIds[$id] = $id;
        } elseif ($hay !== '') {
            foreach ($merchantRules as $key => $rule) {
                if ($key !== '' && str_contains($hay, (string) $key)) {
                    $id = (int) $rule->tag_id;
                    $tagIds[$id] = $id;
                    break;
                }
            }
        }

        if ($tagIds === [] && $category) {
            $decision = $this->categories->findDecision($categoryMap, $category);
            $action = is_array($decision) ? ($decision['action'] ?? null) : null;
            if ($action === BankCategoryResolver::ACTION_MAP && isset($decision['tag_id'])) {
                $id = (int) $decision['tag_id'];
                if ($id > 0) {
                    $tagIds[$id] = $id;
                }
            } elseif ($action === BankCategoryResolver::ACTION_CREATE) {
                $title = trim((string) ($decision['title'] ?? $category));
                if ($title !== '') {
                    $newTitles[mb_strtolower($title)] = mb_substr($title, 0, 60);
                }
            }
        }

        $status = ImportRow::STATUS_NEW;
        $skipReason = null;
        $error = null;
        $matchedId = null;

        if ($at === null || $amount === null || $amount == 0.0) {
            $status = ImportRow::STATUS_ERROR;
            $error = $at === null ? 'Missing date' : 'Missing amount';
        } elseif ($skipBefore && $at < $skipBefore) {
            $status = ImportRow::STATUS_SKIPPED;
            $skipReason = 'Before ' . $skipBefore;
        } elseif ($currency && ! in_array($currency, ['UAH', '980', '₴', 'GRN', 'ГРН'], true) && ! $includeForeign) {
            $status = ImportRow::STATUS_SKIPPED;
            $skipReason = 'Non-UAH (' . $currency . ')';
        } elseif ($skipNames !== [] && $note && $this->noteMentions($note, $skipNames)) {
            $status = ImportRow::STATUS_TRANSFER;
            $skipReason = 'Skipped by name rule';
        } elseif ($skipTransfers && $note && TransferDetector::isInternal(['description' => $note], $ownIbans, $jarTitles)) {
            $status = ImportRow::STATUS_TRANSFER;
            $skipReason = 'Transfer between your own accounts';
        } elseif ($skipTransfers && $note && preg_match('/\b(переказ|перевод|p2p|transfer to|переказ на)\b/iu', $note)) {
            $status = ImportRow::STATUS_TRANSFER;
            $skipReason = 'Looks like a transfer';
        } elseif ($at && $amount !== null) {
            $key = ImportPlan::key($at, $amount, $note);
            $fuzzy = $at . '|' . number_format($amount, 2, '.', '') . '|' . ($merchantKey ?? '');
            if (isset($existing[$key]) || isset($existing[$fuzzy])) {
                $hit = $existing[$key] ?? $existing[$fuzzy];
                $status = ImportRow::STATUS_DUPLICATE;
                $skipReason = 'Already saved';
                $matchedId = $hit['id'] ?? null;
            }
        }

        return [
            'import_batch_id' => $batchId,
            'line' => $line,
            'status' => $status,
            'at' => $at,
            'amount' => $amount,
            'note' => $note,
            'category' => $category,
            'currency' => $currency,
            'raw' => json_encode($row, JSON_UNESCAPED_UNICODE),
            'tag_ids' => json_encode(array_values($tagIds)),
            'suggested_tag_ids' => json_encode(array_values($tagIds)),
            'new_tag_titles' => json_encode(array_values($newTitles)),
            'manual' => false,
            'skip_reason' => $skipReason,
            'error' => $error,
            'matched_transaction_id' => $matchedId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function rowCategory(array $row): ?string
    {
        foreach ($row['tags'] ?? [] as $title) {
            $name = trim((string) $title);
            if ($name !== '') {
                return mb_substr($name, 0, 80);
            }
        }

        return null;
    }

    private function existingKeys(User $user, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $dates = array_values(array_filter(array_column($rows, 'at'), fn ($d) => is_string($d) && $d !== ''));
        if ($dates === []) {
            return [];
        }
        $keys = [];
        $user->transactions()->onDays(min($dates), max($dates))->get(['id', 'at', 'amount', 'note'])
            ->each(function ($tx) use (&$keys): void {
                $at = substr((string) $tx->at, 0, 10);
                $amount = (float) $tx->amount;
                $keys[ImportPlan::key($at, $amount, $tx->note)] = ['id' => (int) $tx->id];
                $norm = MerchantNormalizer::normalize($tx->note) ?? '';
                $keys[$at . '|' . number_format($amount, 2, '.', '') . '|' . $norm] = ['id' => (int) $tx->id];
            });

        return $keys;
    }

    private function noteMentions(string $note, array $names): bool
    {
        $hay = mb_strtolower($note);
        foreach ($names as $name) {
            if ($name !== '' && str_contains($hay, $name)) {
                return true;
            }
        }

        return false;
    }

    private function headerSignature(array $file): ?string
    {
        if (is_array($file['mapping']['header_cells'] ?? null)) {
            return ImportTemplate::signature($file['mapping']['header_cells']);
        }

        return null;
    }
}
