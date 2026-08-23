<?php

namespace App\Http\Controllers;

use App\Actions\Budgets\CreateBudgetAction;
use App\Actions\Budgets\DeleteBudgetAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\Budgets\UpdateBudgetAction;
use App\Http\Requests\BudgetStoreRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BudgetController extends Controller
{
    public function __construct(
        private readonly ListBudgetsAction $listBudgets,
        private readonly CreateBudgetAction $createBudget,
        private readonly UpdateBudgetAction $updateBudget,
        private readonly DeleteBudgetAction $deleteBudget,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $budgets = $this->listBudgets->execute(auth()->user());

        return BudgetResource::collection($budgets);
    }

    public function store(BudgetStoreRequest $request): BudgetResource
    {
        $budget = $this->createBudget->execute(auth()->user(), $request->validated());

        return new BudgetResource($budget);
    }

    public function update(Budget $budget, BudgetStoreRequest $request): BudgetResource
    {
        $budget = $this->updateBudget->execute(auth()->user(), $budget, $request->validated());

        return new BudgetResource($budget);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $this->deleteBudget->execute(auth()->user(), $budget);

        return response()->json([
            'message' => 'Budget deleted successfully!',
        ]);
    }
}
