<?php

namespace App\Actions\Imports;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateImportRowAction
{
    public function __construct(private readonly CreateImportBatchAction $batches) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, ImportBatch $batch, ImportRow $row, array $data): ImportRow
    {
        if (!$batch->isMutable()) {
            throw ValidationException::withMessages(['batch' => 'This import can no longer be edited.']);
        }

        $changed = false;
        if (isset($data['status']) && in_array($data['status'], [
            ImportRow::STATUS_NEW, ImportRow::STATUS_SKIPPED, ImportRow::STATUS_TRANSFER, ImportRow::STATUS_NEEDS_TAG,
        ], true)) {
            $row->status = $data['status'];
            if ($data['status'] === ImportRow::STATUS_SKIPPED) {
                $row->skip_reason = $row->skip_reason ?: 'Skipped by you';
            }
            $changed = true;
        }

        if (array_key_exists('tag_ids', $data) && is_array($data['tag_ids'])) {
            $owned = $user->tags()->whereIn('id', array_map('intval', $data['tag_ids']))->pluck('id')->all();
            $row->tag_ids = array_values(array_map('intval', $owned));
            $row->new_tag_titles = [];
            if ($row->status === ImportRow::STATUS_NEEDS_TAG && $row->tag_ids !== []) {
                $row->status = ImportRow::STATUS_NEW;
                $row->skip_reason = null;
            }
            $changed = true;
        }

        if ($changed) {
            $row->manual = true;
        }

        $row->save();
        $this->batches->recalculate($batch);

        return $row->fresh();
    }
}
