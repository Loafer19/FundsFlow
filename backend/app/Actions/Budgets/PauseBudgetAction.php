<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class PauseBudgetAction
{
    public function execute(User $user, Budget $budget): Budget
    {
        Gate::forUser($user)->authorize('update', $budget);

        $budget->currentPeriod?->update(['ends_at' => now()->toDateString()]);

        return $budget->load('periods.tags');
    }
}
