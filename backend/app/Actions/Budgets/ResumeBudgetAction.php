<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ResumeBudgetAction
{
    public function execute(User $user, Budget $budget): Budget
    {
        Gate::forUser($user)->authorize('update', $budget);

        if ($budget->currentPeriod) {
            return $budget->load('periods.tags');
        }

        $last = $budget->periods()->first();

        if (! $last) {
            throw new RuntimeException('Cannot resume a budget with no periods.');
        }

        $period = $budget->periods()->create([
            'amount' => $last->amount,
            'length' => $last->length,
            'starts_at' => now()->toDateString(),
            'ends_at' => null,
        ]);

        $period->tags()->sync($last->tags->pluck('id'));

        return $budget->load('periods.tags');
    }
}
