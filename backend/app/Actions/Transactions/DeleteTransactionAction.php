<?php

namespace App\Actions\Transactions;

use App\Events\TransactionsChanged;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DeleteTransactionAction
{
    public function execute(User $user, Transaction $transaction): void
    {
        Gate::forUser($user)->authorize('delete', $transaction);

        $id = (int) $transaction->id;
        $userId = (int) $user->id;
        $transaction->loadMissing('attachments');
        $files = $transaction->attachments
            ->map(fn ($attachment) => [$attachment->disk, $attachment->path])
            ->all();

        $transaction->delete();

        // Files go after commit: a rolled-back delete must not leave rows pointing at nothing.
        DB::afterCommit(function () use ($files, $userId, $id) {
            foreach ($files as [$disk, $path]) {
                if (is_string($disk) && is_string($path) && $path !== '') {
                    Storage::disk($disk)->delete($path);
                }
            }

            TransactionsChanged::dispatch($userId, 'deleted', [$id]);
        });
    }
}
