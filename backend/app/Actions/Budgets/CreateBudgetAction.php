<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetLength;
use App\Models\Budget;
use App\Models\User;
use App\Support\TagIds;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateBudgetAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): Budget
    {
        Gate::forUser($user)->authorize('create', Budget::class);

        $tagIds = TagIds::forSync($user, $data['tag_ids']);

        if ($tagIds === []) {
            throw ValidationException::withMessages(['tag_ids' => 'Pick at least one existing tag.']);
        }

        $budget = $user->budgets()->create([
            'title' => $data['title'] ?? null,
        ]);

        $length = BudgetLength::from($data['length']);
        $now = $user->nowInTimezone();
        $startsAt = ($data['align_to_calendar'] ?? false) ? $length->calendarStart($now) : $now;

        $period = $budget->periods()->create([
            'amount' => $data['amount'],
            'length' => $data['length'],
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => null,
        ]);


        $period->tags()->sync($tagIds);

        return $budget->load('periods.tags');
    }
}
