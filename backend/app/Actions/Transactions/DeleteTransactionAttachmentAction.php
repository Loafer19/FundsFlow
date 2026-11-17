<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DeleteTransactionAttachmentAction
{
    public function execute(
        User $user,
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): void {
        Gate::forUser($user)->authorize('update', $transaction);

        if ((int) $attachment->transaction_id !== (int) $transaction->id || (int) $attachment->user_id !== (int) $user->id) {
            abort(404);
        }

        $disk = $attachment->disk;
        $path = $attachment->path;
        $attachment->delete();

        DB::afterCommit(function () use ($disk, $path) {
            if (is_string($disk) && is_string($path) && $path !== '') {
                Storage::disk($disk)->delete($path);
            }
        });
    }
}
