<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\CreateTransactionsAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Actions\Transactions\DeleteTransactionsAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Actions\Transactions\UpdateTransactionsAction;
use App\Enums\TransactionSource;
use App\Http\Requests\TransactionBulkDeleteRequest;
use App\Http\Requests\TransactionBulkStoreRequest;
use App\Http\Requests\TransactionBulkUpdateRequest;
use App\Http\Requests\TransactionStoreRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(
        private readonly ListTransactionsAction $listTransactions,
        private readonly CreateTransactionAction $createTransaction,
        private readonly CreateTransactionsAction $createTransactions,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly UpdateTransactionsAction $updateTransactions,
        private readonly DeleteTransactionAction $deleteTransaction,
        private readonly DeleteTransactionsAction $deleteTransactions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = $this->listTransactions->execute($request->user());

        return TransactionResource::collection($transactions);
    }

    public function store(TransactionStoreRequest $request): TransactionResource
    {
        $source = $request->header('X-FundsFlow-Source') === TransactionSource::Mcp->value
            ? TransactionSource::Mcp
            : TransactionSource::Web;

        $transaction = $this->createTransaction->execute($request->user(), $request->validated(), $source);

        return new TransactionResource($transaction);
    }

    public function storeBulk(TransactionBulkStoreRequest $request): AnonymousResourceCollection
    {
        $source = $request->header('X-FundsFlow-Source') === TransactionSource::Mcp->value
            ? TransactionSource::Mcp
            : TransactionSource::Web;

        $transactions = $this->createTransactions->execute(
            $request->user(),
            $request->validated('transactions'),
            $source,
        );

        return TransactionResource::collection($transactions);
    }

    public function update(Transaction $transaction, TransactionStoreRequest $request): TransactionResource
    {
        $transaction = $this->updateTransaction->execute($request->user(), $transaction, $request->validated());

        return new TransactionResource($transaction);
    }

    public function updateBulk(TransactionBulkUpdateRequest $request): AnonymousResourceCollection
    {
        $transactions = $this->updateTransactions->execute(
            $request->user(),
            $request->validated('transactions'),
        );

        return TransactionResource::collection($transactions);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->deleteTransaction->execute($request->user(), $transaction);

        return response()->json([
            'message' => 'Transaction deleted successfully!',
        ]);
    }

    public function destroyBulk(TransactionBulkDeleteRequest $request): JsonResponse
    {
        $deleted = $this->deleteTransactions->execute(
            $request->user(),
            $request->validated('ids'),
        );

        return response()->json([
            'message' => 'Transactions deleted successfully!',
            'deleted' => $deleted,
        ]);
    }
}
