<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            foreach ($items as $i => $item) {
                $transaction = Transaction::query()
                    ->where('user_id', $user->id)
                    ->whereKey($item['id'])
                    ->firstOrFail();

                unset($item['id']);

                try {
                    $updated->push($this->updateTransaction->execute($user, $transaction, $item));
                } catch (ValidationException $e) {
                    // "data.liters" → "transactions.3.data.liters", so the failing row is clear.
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                        static fn (array $messages, string $key) => ["transactions.{$i}.{$key}" => $messages],
                    )->all());
                }
            }

            return $updated;
        });
    }
}
