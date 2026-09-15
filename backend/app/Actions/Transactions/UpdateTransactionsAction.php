<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdateTransactionsAction
{
    public function __construct(
        private readonly UpdateTransactionAction $updateTransaction,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, Transaction>
     */
    public function execute(User $user, array $items): Collection
    {
        return DB::transaction(function () use ($user, $items) {
            $updated = collect();

            foreach ($items as $item) {
                $transaction = Transaction::query()
                    ->where('user_id', $user->id)
                    ->whereKey($item['id'])
                    ->firstOrFail();

                unset($item['id']);

                $updated->push($this->updateTransaction->execute($user, $transaction, $item));
            }

            return $updated;
        });
    }
}
