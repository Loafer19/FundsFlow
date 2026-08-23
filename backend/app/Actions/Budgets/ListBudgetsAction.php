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

        return $user->budgets()->with('periods.tags')->get();
    }
}
