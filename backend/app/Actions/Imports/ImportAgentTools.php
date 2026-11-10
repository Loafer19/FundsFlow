<?php

namespace App\Actions\Imports;

use App\Actions\Agent\AgentToolExecutor;
use App\Jobs\CommitImportBatchJob;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;

class ImportAgentTools
{
    public function __construct(
        private readonly CreateImportBatchAction $batches,
        private readonly ApplyImportRulesAction $apply,
        private readonly UpdateImportRowAction $updateRowAction,
        private readonly CommitImportBatchAction $commit,
        private readonly UndoImportBatchAction $undo,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $fn = AgentToolExecutor::fn(...);

        return [
            $fn('get_import_preview', 'Get the summary of an import preview batch (rows, period, new/duplicate/transfer/skipped counts, bank categories and their current decisions, URL). Use the latest batch if import_id is omitted.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string', 'description' => 'Preview UUID from the import page URL'],
                ],
            ]),
            $fn('list_import_categories', 'List the bank-file categories in an import preview: each name, how many rows, and the current decision (map to a tag, create as a new tag, or ignore).', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                ],
            ]),
            $fn('set_import_category', 'Set how one bank-file category should be tagged: map it to an existing tag, create it as a new tag (optionally under a different name), or ignore it. Does not save transactions; merchant rules still win for a matching shop. New tags are created only when the import is committed.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                    'category' => ['type' => 'string', 'description' => 'Category name exactly as it appears in the file'],
                    'action' => ['type' => 'string', 'enum' => ['map', 'create', 'ignore']],
                    'tag_id' => ['type' => 'integer', 'description' => 'Existing tag id when action is map'],
                    'tag_title' => ['type' => 'string', 'description' => 'Existing tag title when action is map, or the new tag name when action is create (rename)'],
                    'title' => ['type' => 'string', 'description' => 'New tag name when action is create; defaults to the category name'],
                ],
                'required' => ['category', 'action'],
            ]),
            $fn('apply_import_rules', 'Update an import preview with bulk rules: map merchants to tags (e.g. Glovo → Food) — this persists a merchant rule — skip transfers to a name, skip rows before a date, include or exclude non-UAH rows, or apply a free-text instruction. Does not save transactions; the preview page refreshes. Does not overwrite tags the user set on a single row.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                    'instruction' => ['type' => 'string', 'description' => 'e.g. “Glovo is Food, skip transfers to Olya, skip before 2025”'],
                    'merchant_map' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Merchant substring → existing tag title'],
                    'skip_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'skip_before' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'include_foreign' => ['type' => 'boolean'],
                    'skip_transfers' => ['type' => 'boolean'],
                ],
            ]),
            $fn('update_import_row', 'Change tags or status on a single preview row (by line number). This is a one-off edit: it is not overwritten by later bulk rules and it does not create a merchant rule. Use apply_import_rules to tag every row for a merchant.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                    'line' => ['type' => 'integer', 'description' => 'Line number shown on the preview table'],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'tag_title' => ['type' => 'string', 'description' => 'Existing tag title to assign (instead of tag_ids)'],
                    'status' => ['type' => 'string', 'enum' => ['new', 'skipped', 'transfer', 'needs_tag']],
                ],
                'required' => ['line'],
            ]),
            $fn('commit_import', 'Save the new rows of an import preview as transactions. Prefer the Import button on the preview page. On Telegram, tell the user to open the preview page instead of calling this.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                    'allow_from_telegram' => ['type' => 'boolean', 'description' => 'Must stay false; Telegram cannot import without the preview page'],
                ],
            ]),
            $fn('undo_import', 'Delete every transaction created by a committed import batch, and unused tags this import created.', [
                'type' => 'object',
                'properties' => [
                    'import_id' => ['type' => 'string'],
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(User $user, string $name, array $args, string $channel = 'web'): array
    {
        return match ($name) {
            'get_import_preview' => $this->summarize($user, $args),
            'list_import_categories' => $this->listCategories($user, $args),
            'set_import_category' => $this->setCategory($user, $args),
            'apply_import_rules' => $this->applyRules($user, $args),
            'update_import_row' => $this->updateRow($user, $args),
            'commit_import' => $this->commitBatch($user, $args, $channel),
            'undo_import' => $this->undoBatch($user, $args),
            default => ['error' => 'Unknown import tool'],
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function summarize(User $user, array $args): array
    {
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import preview found. Ask the user to send or upload a statement first.'];
        }

        return [
            'import_id' => $batch->uuid,
            'url' => $batch->previewUrl(),
            'status' => $batch->status,
            'filename' => $batch->filename,
            'summary' => $this->batches->summaryLine($user, $batch),
            'new' => $batch->new_count,
            'duplicates' => $batch->duplicate_count,
            'transfers' => $batch->transfer_count,
            'skipped' => $batch->skipped_count,
            'needs_tag' => $batch->needs_tag_count,
            'errors' => $batch->error_count,
            'income_total' => (float) $batch->income_total,
            'expense_total' => (float) $batch->expense_total,
            'notes' => $batch->notes,
            'categories' => $this->categoryList($user, $batch),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function listCategories(User $user, array $args): array
    {
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import preview found.'];
        }

        return [
            'import_id' => $batch->uuid,
            'url' => $batch->previewUrl(),
            'categories' => $this->categoryList($user, $batch),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryList(User $user, ImportBatch $batch): array
    {
        $counts = $this->batches->categoryCounts($batch->draft ?? []);
        $tags = $user->tags()->get(['id', 'title', 'emoji'])->keyBy('id');
        $out = [];
        foreach ($batch->category_map ?? [] as $name => $decision) {
            if (!is_string($name) || !is_array($decision)) {
                continue;
            }
            $action = $decision['action'] ?? 'ignore';
            $tag = ($action === 'map' && isset($decision['tag_id'])) ? $tags->get((int) $decision['tag_id']) : null;
            $out[] = [
                'name' => $name,
                'count' => $counts[$name] ?? 0,
                'action' => $action,
                'tag_id' => $tag?->id,
                'tag_title' => $tag?->title,
                'create_title' => $action === 'create' ? ($decision['title'] ?? $name) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function setCategory(User $user, array $args): array
    {
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import preview found.'];
        }
        $name = trim((string) ($args['category'] ?? ''));
        $action = $args['action'] ?? $args['category_action'] ?? null;
        if ($name === '' || !in_array($action, ['map', 'create', 'ignore'], true)) {
            return ['error' => 'Provide a category name and an action of map, create, or ignore.'];
        }
        $payload = [
            'category' => $name,
            'category_action' => $action,
            'tag_id' => $args['tag_id'] ?? null,
            'title' => $args['title'] ?? $args['tag_title'] ?? null,
        ];
        if ($action === 'map' && empty($payload['tag_id']) && is_string($args['tag_title'] ?? null)) {
            $tag = $user->tags()->get()->first(fn ($item) => mb_strtolower(trim((string) $item->title)) === mb_strtolower(trim((string) $args['tag_title'])));
            if (!$tag) {
                return ['error' => 'No tag named ' . $args['tag_title'] . '.'];
            }
            $payload['tag_id'] = (int) $tag->id;
        }
        $batch = $this->apply->execute($user, $batch, $payload);

        return $this->listCategories($user, ['import_id' => $batch->uuid]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function applyRules(User $user, array $args): array
    {
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import preview found.'];
        }
        $batch = $this->apply->execute($user, $batch, $args);

        return $this->summarize($user, ['import_id' => $batch->uuid]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function updateRow(User $user, array $args): array
    {
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import preview found.'];
        }
        $line = (int) ($args['line'] ?? 0);
        $row = $batch->rows()->where('line', $line)->first();
        if (!$row instanceof ImportRow) {
            return ['error' => 'No preview row at line ' . $line . '.'];
        }
        $data = [];
        if (isset($args['status'])) {
            $data['status'] = $args['status'];
        }
        if (isset($args['tag_ids']) && is_array($args['tag_ids'])) {
            $data['tag_ids'] = $args['tag_ids'];
        } elseif (is_string($args['tag_title'] ?? null) && trim((string) $args['tag_title']) !== '') {
            $tag = $user->tags()->get()->first(fn ($item) => mb_strtolower(trim((string) $item->title)) === mb_strtolower(trim((string) $args['tag_title'])));
            if (!$tag) {
                return ['error' => 'No tag named ' . $args['tag_title'] . '.'];
            }
            $data['tag_ids'] = [(int) $tag->id];
        }
        if ($data === []) {
            return ['error' => 'Provide tag_ids, tag_title, or status.'];
        }
        $this->updateRowAction->execute($user, $batch, $row, $data);

        return $this->summarize($user, ['import_id' => $batch->uuid]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function commitBatch(User $user, array $args, string $channel): array
    {
        if ($channel === 'telegram' && empty($args['allow_from_telegram'])) {
            $batch = $this->find($user, $args);
            $url = $batch?->previewUrl() ?? (rtrim((string) config('app.frontend_url'), '/') . '/import');

            return [
                'error' => 'Do not import from Telegram directly. Send the preview link and ask the user to press Import on the page: ' . $url,
            ];
        }
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import preview found.'];
        }
        if ($batch->total_rows >= 200) {
            $batch->update(['status' => ImportBatch::STATUS_COMMITTING, 'progress' => 1]);
            CommitImportBatchJob::dispatch($batch->id)->afterResponse();

            return ['status' => 'committing', 'url' => $batch->previewUrl(), 'summary' => 'Import started. The preview page will show progress.'];
        }
        $this->commit->execute($user, $batch);
        $batch = $batch->fresh();

        return ['status' => $batch->status, 'url' => $batch->previewUrl(), 'imported' => $batch->new_count + $batch->needs_tag_count];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function undoBatch(User $user, array $args): array
    {
        $batch = $this->find($user, $args);
        if (!$batch) {
            return ['error' => 'No import found.'];
        }
        $this->undo->execute($user, $batch);

        return ['status' => 'undone', 'url' => $batch->previewUrl()];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function find(User $user, array $args): ?ImportBatch
    {
        $uuid = is_string($args['import_id'] ?? null) ? $args['import_id'] : null;
        if ($uuid) {
            return $user->importBatches()->where('uuid', $uuid)->first();
        }

        return $user->importBatches()->latest('id')->first();
    }
}
