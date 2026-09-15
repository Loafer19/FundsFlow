<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteTransactionAttachmentAction
{
    public function execute(
        User $user,
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): void {
        Gate::forUser($user)->authorize('update', $transaction);

        if ($attachment->transaction_id !== $transaction->id || $attachment->user_id !== $user->id) {
            abort(404);
        }

        $attachment->deleteFile();
        $attachment->delete();
    }
}
