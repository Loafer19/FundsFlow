<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Enums\TransactionSource;
use App\Http\Requests\TransactionStoreRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(
        private readonly ListTransactionsAction $listTransactions,
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly DeleteTransactionAction $deleteTransaction,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $transactions = $this->listTransactions->execute(auth()->user());

        return TransactionResource::collection($transactions);
    }

    public function store(TransactionStoreRequest $request): TransactionResource
    {
        $transaction = $this->createTransaction->execute(auth()->user(), $request->validated(), TransactionSource::Web);

        return new TransactionResource($transaction);
    }

    public function update(Transaction $transaction, TransactionStoreRequest $request): TransactionResource
    {
        $transaction = $this->updateTransaction->execute(auth()->user(), $transaction, $request->validated());

        return new TransactionResource($transaction);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $this->deleteTransaction->execute(auth()->user(), $transaction);

        return response()->json([
            'message' => 'Transaction deleted successfully!',
        ]);
    }
}
