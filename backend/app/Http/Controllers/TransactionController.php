<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionStoreRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Transaction::class);

        $transactions = auth()
            ->user()
            ->transactions()
            ->with('tags')
            ->latest('at')
            ->get();

        return TransactionResource::collection($transactions);
    }

    public function store(TransactionStoreRequest $request): TransactionResource
    {
        Gate::authorize('create', Transaction::class);

        $data = $request->validated();

        $tags = $request->array('tags');
        unset($data['tags']);

        $transaction = auth()
            ->user()
            ->transactions()
            ->create($data);

        $transaction->tags()->attach($tags);

        $transaction->load('tags');

        return new TransactionResource($transaction);
    }

    public function update(TransactionStoreRequest $request, Transaction $transaction)
    {
        Gate::authorize('update', $transaction);

        $data = $request->validated();

        $tags = $request->array('tags');
        unset($data['tags']);

        $transaction->update($data);

        $transaction->tags()->sync($tags);

        $transaction->load('tags');

        return new TransactionResource($transaction);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        Gate::authorize('delete', $transaction);

        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully!',
        ]);
    }
}
