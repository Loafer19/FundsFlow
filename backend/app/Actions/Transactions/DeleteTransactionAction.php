<?php

namespace App\Actions\Transactions;

use App\Events\TransactionsChanged;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteTransactionAction
{
    public function execute(User $user, Transaction $transaction): void
    {
        Gate::forUser($user)->authorize('delete', $transaction);

        $id = (int) $transaction->id;
        $transaction->loadMissing('attachments');

        foreach ($transaction->attachments as $attachment) {
            $attachment->deleteFile();
        }

        $transaction->delete();
        TransactionsChanged::dispatch((int) $user->id, 'deleted', [$id]);
    }
}
