<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, Transaction $transaction, array $data): Transaction
    {
        Gate::forUser($user)->authorize('update', $transaction);

        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $transaction->update($data);

        $transaction->tags()->sync($tags);

        return $transaction->load('tags');
    }
}
