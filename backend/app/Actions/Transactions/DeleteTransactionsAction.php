<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTransactionsAction
{
    public function __construct(
        private readonly DeleteTransactionAction $deleteTransaction,
    ) {}

    /**
     * @param  list<int>  $ids
     */
    public function execute(User $user, array $ids): int
    {
        return DB::transaction(function () use ($user, $ids) {
            $transactions = Transaction::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $ids)
                ->with('attachments')
                ->get();

            foreach ($transactions as $transaction) {
                $this->deleteTransaction->execute($user, $transaction);
            }

            return $transactions->count();
        });
    }
}
