<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ListBudgetsAction
{
    /**
     * @return Collection<int, Budget>
     */
    public function execute(User $user): Collection
    {
        Gate::forUser($user)->authorize('viewAny', Budget::class);

        $budgets = $user->budgets()->with('periods.tags')->get();

        // Bucket maths reads budget.anchor_date and sibling periods: link them without extra queries.
        foreach ($budgets as $budget) {
            $budget->periods->each(fn ($period) => $period->setRelation('budget', $budget));
        }

        return $budgets;
    }
}
