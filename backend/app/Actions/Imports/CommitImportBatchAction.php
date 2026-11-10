<?php

namespace App\Actions\Imports;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Transactions\CreateTransactionsAction;
use App\Enums\TransactionSource;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommitImportBatchAction
{
    public function __construct(
        private readonly CreateTransactionsAction $createTransactions,
        private readonly CreateTagAction $createTag,
    ) {}

    public function execute(User $user, ImportBatch $batch): ImportBatch
    {
        if ($batch->status === ImportBatch::STATUS_COMMITTED) {
            return $batch;
        }
        if (!in_array($batch->status, [ImportBatch::STATUS_PREVIEWING, ImportBatch::STATUS_FAILED, ImportBatch::STATUS_COMMITTING], true)) {
            throw ValidationException::withMessages(['batch' => 'This import cannot be committed.']);
        }

        $batch->forceFill(['status' => ImportBatch::STATUS_COMMITTING, 'progress' => 5, 'error_message' => null])->save();

        try {
            $created = DB::transaction(function () use ($user, $batch) {
                $createdIds = [];
                $categoryToId = [];
                $ownedTitles = [];
                foreach ($user->tags()->get(['id', 'title']) as $tag) {
                    $ownedTitles[mb_strtolower(trim((string) $tag->title))] = (int) $tag->id;
                }

                foreach ($batch->category_map ?? [] as $name => $decision) {
                    if (!is_array($decision) || ($decision['action'] ?? '') !== BankCategoryResolver::ACTION_CREATE) {
                        continue;
                    }
                    $title = trim((string) ($decision['title'] ?? $name));
                    if ($title === '') {
                        continue;
                    }
                    $lower = mb_strtolower($title);
                    if (isset($ownedTitles[$lower])) {
                        $id = $ownedTitles[$lower];
                    } else {
                        $tag = $this->createTag->execute($user, ['title' => $title, 'emoji' => '🏷']);
                        $id = (int) $tag->id;
                        $ownedTitles[$lower] = $id;
                        $createdIds[] = $id;
                    }
                    $categoryToId[mb_strtolower(trim((string) $name))] = $id;
                }

                $batch->created_tag_ids = $createdIds;
                $batch->save();

                $rows = $batch->rows()->whereIn('status', [ImportRow::STATUS_NEW, ImportRow::STATUS_NEEDS_TAG])->get();
                $owned = array_fill_keys($user->tags()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
                $items = [];
                foreach ($rows as $row) {
                    if (!$row->at || $row->amount === null || (float) $row->amount == 0.0) {
                        continue;
                    }
                    $tags = [];
                    foreach ($row->tag_ids ?? [] as $id) {
                        $id = (int) $id;
                        if (isset($owned[$id])) {
                            $tags[] = $id;
                        }
                    }
                    if (!$row->manual && $tags === [] && $row->category) {
                        $id = $categoryToId[mb_strtolower($row->category)] ?? null;
                        if ($id && !in_array($id, $tags, true)) {
                            $tags[] = $id;
                        }
                    }
                    $items[] = [
                        'amount' => round((float) $row->amount, 2),
                        'at' => $row->at->toDateString(),
                        'note' => $row->note,
                        'tags' => $tags,
                        'import_batch_id' => $batch->id,
                    ];
                }

                $batch->update(['progress' => 40]);

                return $items === []
                    ? collect()
                    : $this->createTransactions->execute($user, $items, TransactionSource::Import);
            });

            $batch->forceFill([
                'status' => ImportBatch::STATUS_COMMITTED,
                'progress' => 100,
                'committed_at' => now(),
                'error_message' => null,
            ])->save();

            unset($created);

            return $batch->fresh();
        } catch (Throwable $e) {
            $batch->forceFill([
                'status' => ImportBatch::STATUS_FAILED,
                'progress' => 0,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
            throw $e;
        }
    }
}
