<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionStoreRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $transactions = auth()
            ->user()
            ->transactions()
            ->latest('at')
            ->get();

        return TransactionResource::collection($transactions);
    }

    public function store(TransactionStoreRequest $request): TransactionResource
    {
        $transaction = auth()
            ->user()
            ->transactions()
            ->create($request->validated());

        return new TransactionResource($transaction);
    }

    public function show(Transaction $transaction)
    {
        //
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction)
    {
        //
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully!',
        ]);
    }
}
