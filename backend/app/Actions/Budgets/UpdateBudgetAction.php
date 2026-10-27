<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetLength;
use App\Models\Budget;
use App\Models\BudgetPeriod;
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

        return $this->updateLimit($user, $budget, $current, $data, $newTagIds->all());
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

    /**
     * Limits keep history: changing amount, length or tags closes the open period
     * today and opens a new one on the same bucket grid (budgets.anchor_date), so a
     * monthly limit from the 15th still runs 15th → 14th. Same-day edits are applied
     * in place. Moving start_date re-anchors the budget and rewrites the open period's
     * start in place (no history row). On a paused limit the edit resumes it from
     * start_date (default today; a given date re-anchors). Legacy align_to_calendar
     * puts the budget on calendar weeks/months/years (anchor null).
     *
     * @param array<string, mixed> $data
     * @param list<int> $tagIds
     */
    private function updateLimit(User $user, Budget $budget, ?BudgetPeriod $current, array $data, array $tagIds): Budget
    {
        $length = BudgetLength::from($data['length']);
        $today = $user->todayDateString();
        $startDate = isset($data['start_date']) ? (string) $data['start_date'] : null;
        // Legacy flag: put the limit on the calendar grid from the current week/month/year.
        $calendarStart = !$startDate && !empty($data['align_to_calendar'])
            ? $length->calendarStart($user->nowInTimezone())->toDateString()
            : null;
        $fields = ['amount' => $data['amount'], 'length' => $data['length']];

        if ($current) {
            if ($startDate !== null && $startDate !== $current->starts_at->toDateString()) {
                $this->assertStartAfterHistory($budget, $current, $startDate);
                $budget->update(['anchor_date' => $startDate]);
                $current->update($fields + ['starts_at' => $startDate]);
                $current->tags()->sync($tagIds);

                return $budget->load('periods.tags');
            }

            $unchanged = (float) $current->amount === (float) $data['amount']
                && $current->length->value === $data['length']
                && $current->tags->pluck('id')->sort()->values()->all() === $tagIds;

            if ($unchanged) {
                if ($calendarStart) {
                    $budget->update(['anchor_date' => null]);
                }

                return $budget->load('periods.tags');
            }

            // Same-day edits refine the open period in place instead of
            // leaving a zero-length history row behind.
            if ($current->starts_at->toDateString() === $today) {
                $legacy = [];

                if ($calendarStart) {
                    // Count from the calendar start, but never over earlier history.
                    $lastEnd = $this->lastClosedEnd($budget, $current);
                    $legacy = ['starts_at' => max($calendarStart, $lastEnd ?? $calendarStart)];
                    $budget->update(['anchor_date' => null]);
                }
                $current->update($fields + $legacy);
                $current->tags()->sync($tagIds);

                return $budget->load('periods.tags');
            }

            $current->update(['ends_at' => $today]);
            $startsAt = $today;
        } else {
            $startsAt = $startDate ?? $today;
            $this->assertStartAfterHistory($budget, null, $startsAt);

            if ($startDate !== null) {
                $budget->update(['anchor_date' => $startDate]);
            }
        }

        if ($calendarStart) {
            $budget->update(['anchor_date' => null]);
        }

        $period = $budget->periods()->create($fields + [
            'starts_at' => $startsAt,
            'ends_at' => null,
        ]);

        $period->tags()->sync($tagIds);

        return $budget->load('periods.tags');
    }

    /**
     * A limit's open period cannot start before the previous settings ended.
     */
    private function assertStartAfterHistory(Budget $budget, ?BudgetPeriod $except, string $startDate): void
    {
        $lastEnd = $this->lastClosedEnd($budget, $except);

        if ($lastEnd !== null && $startDate < $lastEnd) {
            throw ValidationException::withMessages([
                'start_date' => "The start date must be on or after {$lastEnd}, when the previous settings ended.",
            ]);
        }
    }

    private function lastClosedEnd(Budget $budget, ?BudgetPeriod $except): ?string
    {
        $lastEnd = $budget->periods()
            ->whereNotNull('ends_at')
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->max('ends_at');

        return $lastEnd ? substr((string) $lastEnd, 0, 10) : null;
    }
}
