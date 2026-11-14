<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTransactionsAction
{
    public function __construct(
        private readonly CreateTransactionAction $createTransaction,
    ) {}

    /**
     * @param list<array<string, mixed>> $items
     * @return Collection<int, Transaction>
     */
    public function execute(User $user, array $items, TransactionSource $source, bool $requireAllFields = true): Collection
    {
        return DB::transaction(function () use ($user, $items, $source, $requireAllFields) {
            $created = collect();

            foreach ($items as $i => $item) {
                try {
                    $created->push($this->createTransaction->execute($user, $item, $source, $requireAllFields));
                } catch (ValidationException $e) {
                    // "data.liters" → "transactions.3.data.liters", so the failing row is clear.
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                        static fn (array $messages, string $key) => ["transactions.{$i}.{$key}" => $messages],
                    )->all());
                }
            }

            return $created;
        });
    }
}
