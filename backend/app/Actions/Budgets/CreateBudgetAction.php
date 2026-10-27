<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetLength;
use App\Enums\BudgetType;
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

        $type = BudgetType::tryFrom((string) ($data['type'] ?? '')) ?? BudgetType::Limit;

        if ($type === BudgetType::Goal) {
            return $this->createGoal($user, $data, $tagIds);
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

    /**
     * A goal is one budget row (deadline, start amount) with a single open
     * period from start_date (default today): amount = target, tags = contribution tags. The
     * period length is a stored placeholder that goals ignore.
     *
     * @param array<string, mixed> $data
     * @param list<int> $tagIds
     */
    private function createGoal(User $user, array $data, array $tagIds): Budget
    {
        $budget = $user->budgets()->create([
            'title' => $data['title'] ?? null,
            'type' => BudgetType::Goal,
            'target_date' => $data['target_date'] ?? null,
            'start_amount' => $data['start_amount'] ?? null,
        ]);

        $period = $budget->periods()->create([
            'amount' => $data['amount'],
            'length' => BudgetLength::Month->value,
            'starts_at' => $data['start_date'] ?? $user->todayDateString(),
            'ends_at' => null,
        ]);

        $period->tags()->sync($tagIds);

        return $budget->load('periods.tags');
    }
}
