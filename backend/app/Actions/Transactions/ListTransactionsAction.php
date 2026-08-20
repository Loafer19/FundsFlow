<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ListTransactionsAction
{
    /**
     * @return Collection<int, Transaction>
     */
    public function execute(User $user): Collection
    {
        Gate::forUser($user)->authorize('viewAny', Transaction::class);

        return $user->transactions()
            ->with('tags')
            ->latest('at')
            ->get();
    }
}
