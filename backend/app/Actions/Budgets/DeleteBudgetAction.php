<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteBudgetAction
{
    public function execute(User $user, Budget $budget): void
    {
        Gate::forUser($user)->authorize('delete', $budget);

        $budget->delete();
    }
}
