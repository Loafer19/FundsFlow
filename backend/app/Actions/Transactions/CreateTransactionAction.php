<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): Transaction
    {
        Gate::forUser($user)->authorize('create', Transaction::class);

        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $transaction = $user->transactions()->create($data);

        $transaction->tags()->attach($tags);

        return $transaction->load('tags');
    }
}
