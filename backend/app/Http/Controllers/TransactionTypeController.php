<?php

namespace App\Http\Controllers;

use App\Actions\TransactionTypes\SaveTransactionTypeAction;
use App\Events\TransactionsChanged;
use App\Http\Resources\TransactionTypeResource;
use App\Models\TransactionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionTypeController extends Controller
{
    public function __construct(private readonly SaveTransactionTypeAction $save) {}

    /** Active and archived types (archived ones still label their old transactions). */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TransactionTypeResource::collection(
            $request->user()->transactionTypes()->withTrashed()->withCount('transactions')->orderBy('name')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $type = $this->save->execute($request->user(), $request->all());

        return (new TransactionTypeResource($type->loadCount('transactions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $type): TransactionTypeResource
    {
        $model = $this->own($request, $type);
        $saved = $this->save->execute($request->user(), $request->all(), $model);
        // Widgets and cards show the type label and field names.
        TransactionsChanged::dispatch((int) $request->user()->id, 'updated', []);

        return new TransactionTypeResource($saved->loadCount('transactions'));
    }

    /** Archive: hidden from pickers and the assistant; its transactions keep the type and values. */
    public function destroy(Request $request, int $type): JsonResponse
    {
        $this->own($request, $type)->delete();

        return response()->json(['message' => 'Type archived!']);
    }

    /** Back from the archive. */
    public function restore(Request $request, int $type): TransactionTypeResource
    {
        $model = $request->user()->transactionTypes()->onlyTrashed()->findOrFail($type);
        $model->restore();

        return new TransactionTypeResource($model->loadCount('transactions'));
    }

    private function own(Request $request, int $id): TransactionType
    {
        return $request->user()->transactionTypes()->findOrFail($id);
    }
}
