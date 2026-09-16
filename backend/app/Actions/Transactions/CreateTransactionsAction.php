<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateTransactionsAction
{
    public function __construct(
        private readonly CreateTransactionAction $createTransaction,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, Transaction>
     */
    public function execute(User $user, array $items, TransactionSource $source): Collection
    {
        return DB::transaction(function () use ($user, $items, $source) {
            $created = collect();

            foreach ($items as $item) {
                $created->push($this->createTransaction->execute($user, $item, $source));
            }

            return $created;
        });
    }
}
