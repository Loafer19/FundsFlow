<?php

namespace App\Http\Controllers;

use App\Actions\RecurringTransactions\CreateRecurringTransactionAction;
use App\Actions\RecurringTransactions\DeleteRecurringTransactionAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\RecurringTransactions\UpdateRecurringTransactionAction;
use App\Http\Requests\RecurringTransactionStoreRequest;
use App\Http\Resources\RecurringTransactionResource;
use App\Models\RecurringTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecurringTransactionController extends Controller
{
    public function __construct(
        private readonly ListRecurringTransactionsAction $listRecurringTransactions,
        private readonly CreateRecurringTransactionAction $createRecurringTransaction,
        private readonly UpdateRecurringTransactionAction $updateRecurringTransaction,
        private readonly DeleteRecurringTransactionAction $deleteRecurringTransaction,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $rules = $this->listRecurringTransactions->execute(auth()->user());

        return RecurringTransactionResource::collection($rules);
    }

    public function store(RecurringTransactionStoreRequest $request): RecurringTransactionResource
    {
        $rule = $this->createRecurringTransaction->execute(auth()->user(), $request->validated());

        return new RecurringTransactionResource($rule);
    }

    public function update(RecurringTransaction $recurringTransaction, RecurringTransactionStoreRequest $request): RecurringTransactionResource
    {
        $rule = $this->updateRecurringTransaction->execute(auth()->user(), $recurringTransaction, $request->validated());

        return new RecurringTransactionResource($rule);
    }

    public function destroy(RecurringTransaction $recurringTransaction): JsonResponse
    {
        $this->deleteRecurringTransaction->execute(auth()->user(), $recurringTransaction);

        return response()->json([
            'message' => 'Recurring transaction deleted successfully!',
        ]);
    }
}
