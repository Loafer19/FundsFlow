<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetLength;
use App\Models\Budget;
use App\Models\User;
use App\Support\TagIds;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateBudgetAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, Budget $budget, array $data): Budget
    {
        Gate::forUser($user)->authorize('update', $budget);

        $current = $budget->currentPeriod;
        $newTagIds = collect(TagIds::forSync($user, $data['tag_ids'], $current?->tags->pluck('id') ?? []))
            ->sort()
            ->values();

        if ($newTagIds->isEmpty()) {
            throw ValidationException::withMessages(['tag_ids' => 'Pick at least one existing tag.']);
        }

        if (array_key_exists('title', $data)) {
            $budget->update(['title' => $data['title']]);
        }

        if ($budget->isGoal()) {
            return $this->updateGoal($user, $budget, $data, $newTagIds->all());
        }

        $length = BudgetLength::from($data['length']);
        $now = $user->nowInTimezone();
        $startsAt = ($data['align_to_calendar'] ?? false) ? $length->calendarStart($now) : $now;
        $today = $now->toDateString();

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
            if ($current->starts_at->toDateString() === $today) {
                $current->update([
                    'amount' => $data['amount'],
                    'length' => $data['length'],
                    'starts_at' => $startsAt->toDateString(),
                ]);
                $current->tags()->sync($newTagIds);

                return $budget->load('periods.tags');
            }

            $current->update(['ends_at' => $today]);
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

    /**
     * Goals have no history buckets: target and tags are edited in place on
     * the open period, so progress keeps counting from the original start.
     * Editing a paused goal resumes it (like limits) with a new open period.
     *
     * @param array<string, mixed> $data
     * @param list<int> $tagIds
     */
    private function updateGoal(User $user, Budget $budget, array $data, array $tagIds): Budget
    {
        $budget->fill(array_intersect_key($data, array_flip(['target_date', 'start_amount'])));
        $budget->save();

        $current = $budget->currentPeriod;

        if ($current) {
            $current->update(['amount' => $data['amount']]);
        } else {
            $current = $budget->periods()->create([
                'amount' => $data['amount'],
                'length' => BudgetLength::Month->value,
                'starts_at' => $user->todayDateString(),
                'ends_at' => null,
            ]);
        }

        $current->tags()->sync($tagIds);

        if (!empty($data['start_date'])) {
            $budget->moveGoalStart((string) $data['start_date']);
        }

        return $budget->load('periods.tags');
    }
}
