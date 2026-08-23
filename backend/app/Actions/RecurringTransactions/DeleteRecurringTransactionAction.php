<?php

namespace App\Actions\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteRecurringTransactionAction
{
    public function execute(User $user, RecurringTransaction $recurringTransaction): void
    {
        Gate::forUser($user)->authorize('delete', $recurringTransaction);

        $recurringTransaction->delete();
    }
}
