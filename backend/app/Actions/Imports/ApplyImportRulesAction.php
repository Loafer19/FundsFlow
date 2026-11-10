<?php

namespace App\Actions\Imports;

use App\Actions\Agent\Documents\ImportInstructions;
use App\Actions\Tags\ListTagsAction;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MonobankTagRule;
use App\Models\User;
use App\Services\Monobank\MerchantNormalizer;
use Illuminate\Validation\ValidationException;

class ApplyImportRulesAction
{
    public function __construct(
        private readonly CreateImportBatchAction $batches,
        private readonly ListTagsAction $listTags,
    ) {}

    /**
     * @param  array<string, mixed>  $rules
     */
    public function execute(User $user, ImportBatch $batch, array $rules): ImportBatch
    {
        if (!$batch->isMutable()) {
            throw ValidationException::withMessages(['batch' => 'This import can no longer be edited.']);
        }

        $options = $batch->options ?? [];
        $text = is_string($rules['instruction'] ?? null) ? trim($rules['instruction']) : '';
        if ($text !== '') {
            $this->parsePhrases($text, $options, $rules);
        }

        if (array_key_exists('include_foreign', $rules)) {
            $options['include_foreign'] = (bool) $rules['include_foreign'];
        }
        if (array_key_exists('skip_transfers', $rules)) {
            $options['skip_transfers'] = (bool) $rules['skip_transfers'];
        }
        if (array_key_exists('skip_before', $rules)) {
            $before = $rules['skip_before'];
            $options['skip_before'] = is_string($before) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) ? $before : null;
        }
        if (isset($rules['merchant_map']) && is_array($rules['merchant_map'])) {
            $map = is_array($options['merchant_map'] ?? null) ? $options['merchant_map'] : [];
            $titles = [];
            foreach ($this->listTags->execute($user) as $tag) {
                $titles[mb_strtolower(trim((string) $tag->title))] = (int) $tag->id;
            }
            foreach ($rules['merchant_map'] as $merchant => $tagTitle) {
                if (!is_string($merchant) || trim($merchant) === '' || !is_string($tagTitle)) {
                    continue;
                }
                $key = mb_strtolower((string) (MerchantNormalizer::normalize($merchant) ?? trim($merchant)));
                $map[$key] = trim($tagTitle);
                $tagId = $titles[mb_strtolower(trim($tagTitle))] ?? null;
                $stored = MerchantNormalizer::normalize($merchant);
                if ($tagId && $stored) {
                    MonobankTagRule::query()->updateOrCreate(
                        ['user_id' => $user->id, 'merchant_key' => $stored],
                        ['tag_id' => $tagId, 'mcc' => null],
                    );
                }
            }
            $options['merchant_map'] = $map;
        }
        if (isset($rules['skip_names']) && is_array($rules['skip_names'])) {
            $names = is_array($options['skip_names'] ?? null) ? $options['skip_names'] : [];
            foreach ($rules['skip_names'] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = mb_strtolower(trim($name));
                }
            }
            $options['skip_names'] = array_values(array_unique($names));
        }

        $batch->options = $options;

        if (isset($rules['category_map']) && is_array($rules['category_map'])) {
            foreach ($rules['category_map'] as $name => $decision) {
                if (!is_string($name) || trim($name) === '' || !is_array($decision)) {
                    continue;
                }
                $this->batches->applyCategoryDecision($user, $batch, $name, $decision);
            }
        }
        if (isset($rules['category']) && is_string($rules['category']) && trim($rules['category']) !== '') {
            $action = $rules['category_action'] ?? $rules['action'] ?? null;
            $decision = ['action' => $action];
            if (isset($rules['tag_id'])) {
                $decision['tag_id'] = $rules['tag_id'];
            }
            $title = $rules['title'] ?? $rules['tag_title'] ?? null;
            if (is_string($title) && trim($title) !== '') {
                $decision['title'] = trim($title);
            }
            $this->batches->applyCategoryDecision($user, $batch, $rules['category'], $decision);
        }

        if ($text !== '') {
            $existing = [];
            foreach ($this->listTags->execute($user) as $tag) {
                $existing[mb_strtolower(trim((string) $tag->title))] = trim((string) $tag->title);
            }
            $ops = ImportInstructions::quick($text, [], $existing, true);
            $draft = $batch->draft ?? [];
            $draft['ops'] = ImportInstructions::merge(is_array($draft['ops'] ?? null) ? $draft['ops'] : [], $ops);
            $notes = is_array($draft['notes'] ?? null) ? $draft['notes'] : [];
            $notes[] = $text;
            $draft['notes'] = array_values(array_unique(array_filter($notes, 'is_string')));
            $batch->draft = $draft;
        }

        $batch->save();
        if ($this->shouldRebuild($rules, $text)) {
            $this->batches->rebuildRows($user, $batch);
        }
        if ($this->applyInclusion($batch, $rules)) {
            $this->batches->recalculate($batch);
        }

        return $batch->fresh();
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function shouldRebuild(array $rules, string $text): bool
    {
        foreach (['include_foreign', 'skip_transfers', 'skip_before', 'merchant_map', 'skip_names', 'instruction', 'category_map', 'category'] as $key) {
            if (array_key_exists($key, $rules)) {
                if ($key === 'category' && (!is_string($rules['category']) || trim($rules['category']) === '')) {
                    continue;
                }
                if ($key === 'instruction') {
                    continue;
                }

                return true;
            }
        }

        return $text !== '';
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function applyInclusion(ImportBatch $batch, array $rules): bool
    {
        $include = array_values(array_unique(array_map('intval', $rules['include_ids'] ?? [])));
        $skip = array_values(array_unique(array_map('intval', $rules['skip_ids'] ?? [])));
        if ($include === [] && $skip === []) {
            return false;
        }

        $changed = false;
        $rows = $batch->rows()->whereIn('id', array_values(array_unique([...$include, ...$skip])))->get();
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (in_array($id, $skip, true)) {
                $row->status = ImportRow::STATUS_SKIPPED;
                $row->skip_reason = $row->skip_reason ?: 'Skipped by you';
                $row->manual = true;
                $row->save();
                $changed = true;
                continue;
            }
            if (in_array($id, $include, true) && $row->status !== ImportRow::STATUS_ERROR) {
                $row->status = ImportRow::STATUS_NEW;
                $row->skip_reason = null;
                $row->manual = true;
                $row->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Extra phrases: “all Glovo → Food”, “Glovo is Food”, “skip transfers to Olya”, “skip before 2025”.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $rules
     */
    private function parsePhrases(string $text, array &$options, array &$rules): void
    {
        if (preg_match('/skip before\s+(\d{4})(?:-(\d{2})-(\d{2}))?/iu', $text, $m)) {
            $options['skip_before'] = $m[1] . '-' . ($m[2] ?? '01') . '-' . ($m[3] ?? '01');
            $rules['skip_before'] = $options['skip_before'];
        }
        if (preg_match('/skip(?:\s+transfers?)?(?:\s+to)\s+(.+?)(?:\.|$)/iu', $text, $m)) {
            $name = trim($m[1], " \t\"'«»“”");
            if ($name !== '' && !preg_match('/^before\b/iu', $name)) {
                $names = is_array($options['skip_names'] ?? null) ? $options['skip_names'] : [];
                $names[] = mb_strtolower($name);
                $options['skip_names'] = array_values(array_unique($names));
                $rules['skip_names'] = $options['skip_names'];
            }
        }
        foreach (preg_split('/\R|[;,]/u', $text) ?: [] as $segment) {
            if (!preg_match('/^\s*(?:all\s+)?(.+?)\s*(?:→|->|=>|=| is )\s*(.+?)\s*\.?$/iu', $segment, $m)) {
                continue;
            }
            $from = trim($m[1], " \t\"'«»“”");
            $to = trim($m[2], " \t\"'«»“”");
            if ($from === '' || $to === '' || preg_match('/^skip /iu', $from)) {
                continue;
            }
            $rules['merchant_map'][$from] = $to;
        }
    }
}
