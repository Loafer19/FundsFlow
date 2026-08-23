<?php

namespace App\Actions\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ListRecurringTransactionsAction
{
    /**
     * @return Collection<int, RecurringTransaction>
     */
    public function execute(User $user): Collection
    {
        Gate::forUser($user)->authorize('viewAny', RecurringTransaction::class);

        return $user->recurringTransactions()->with('tags')->get();
    }
}
