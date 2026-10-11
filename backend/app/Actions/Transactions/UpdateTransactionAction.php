<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use App\Support\TagIds;
use Illuminate\Support\Facades\Gate;

class UpdateTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, Transaction $transaction, array $data): Transaction
    {
        Gate::forUser($user)->authorize('update', $transaction);

        // PATCH without `tags` must keep the current tags; only an explicit key (even [] / null) syncs.
        $syncTags = array_key_exists('tags', $data);
        $tags = $syncTags
            ? TagIds::forSync($user, $data['tags'] ?? [], $transaction->tags()->pluck('tags.id'))
            : [];
        unset($data['tags']);

        $transaction->update($data);

        if ($syncTags) {
            $transaction->tags()->sync($tags);
        }

        // Reload from DB so the response reflects stored amount (not in-memory input).
        return $transaction->fresh()->load(['tags', 'attachments']);
    }
}

