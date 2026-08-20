<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data, TransactionSource $source): Transaction
    {
        Gate::forUser($user)->authorize('create', Transaction::class);

        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        $data['source'] = $source;

        $transaction = $user->transactions()->create($data);

        $transaction->tags()->attach($tags);

        return $transaction->load('tags');
    }
}
