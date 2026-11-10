<?php

namespace App\Actions\Imports;

use App\Actions\Tags\DeleteTagAction;
use App\Events\TransactionsChanged;
use App\Models\ImportBatch;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UndoImportBatchAction
{
    public function __construct(private readonly DeleteTagAction $deleteTag) {}

    public function execute(User $user, ImportBatch $batch): ImportBatch
    {
        if ($batch->status !== ImportBatch::STATUS_COMMITTED) {
            throw ValidationException::withMessages(['batch' => 'Only a completed import can be undone.']);
        }

        $ids = $user->transactions()->where('import_batch_id', $batch->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $user->transactions()->where('import_batch_id', $batch->id)->delete();

        if ($ids !== []) {
            TransactionsChanged::dispatch((int) $user->id, 'deleted', $ids);
        }

        foreach ($batch->created_tag_ids ?? [] as $id) {
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }
            $tag = $user->tags()->whereKey($id)->first();

            if (!$tag instanceof Tag) {
                continue;
            }
            $stillUsed = $user->transactions()->whereHas('tags', fn ($query) => $query->where('tags.id', $id))->exists();

            if ($stillUsed) {
                continue;
            }
            $this->deleteTag->execute($user, $tag);
        }

        $batch->forceFill([
            'status' => ImportBatch::STATUS_UNDONE,
            'undone_at' => now(),
            'progress' => 0,
        ])->save();

        return $batch->fresh();
    }
}
