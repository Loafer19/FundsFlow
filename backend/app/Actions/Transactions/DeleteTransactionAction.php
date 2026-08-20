<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteTransactionAction
{
    public function execute(User $user, Transaction $transaction): void
    {
        Gate::forUser($user)->authorize('delete', $transaction);

        $transaction->delete();
    }
}
