<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetLength;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateBudgetAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, Budget $budget, array $data): Budget
    {
        Gate::forUser($user)->authorize('update', $budget);

        if (array_key_exists('title', $data)) {
            $budget->update(['title' => $data['title']]);
        }

        $current = $budget->currentPeriod;
        $newTagIds = collect($data['tag_ids'])->map(fn ($id) => (int) $id)->sort()->values();
        $length = BudgetLength::from($data['length']);
        $startsAt = ($data['align_to_calendar'] ?? false) ? $length->calendarStart() : now();

        if ($current) {
            $currentTagIds = $current->tags->pluck('id')->sort()->values();

            $unchanged = (float) $current->amount === (float) $data['amount']
                && $current->length->value === $data['length']
                && $currentTagIds->all() === $newTagIds->all();

            if ($unchanged) {
                return $budget->load('periods.tags');
            }

            // Same-day edits refine the open period in place instead of
            // leaving a zero-length history row behind.
            if ($current->starts_at->isToday()) {
                $current->update([
                    'amount' => $data['amount'],
                    'length' => $data['length'],
                    'starts_at' => $startsAt->toDateString(),
                ]);
                $current->tags()->sync($newTagIds);

                return $budget->load('periods.tags');
            }

            $current->update(['ends_at' => now()->toDateString()]);
        }

        $period = $budget->periods()->create([
            'amount' => $data['amount'],
            'length' => $data['length'],
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => null,
        ]);

        $period->tags()->sync($newTagIds);

        return $budget->load('periods.tags');
    }
}
