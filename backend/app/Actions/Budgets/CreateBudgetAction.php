<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetLength;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateBudgetAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): Budget
    {
        Gate::forUser($user)->authorize('create', Budget::class);

        $budget = $user->budgets()->create([
            'title' => $data['title'] ?? null,
        ]);

        $length = BudgetLength::from($data['length']);
        $startsAt = ($data['align_to_calendar'] ?? false) ? $length->calendarStart() : now();

        $period = $budget->periods()->create([
            'amount' => $data['amount'],
            'length' => $data['length'],
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => null,
        ]);

        $period->tags()->sync($data['tag_ids']);

        return $budget->load('periods.tags');
    }
}
