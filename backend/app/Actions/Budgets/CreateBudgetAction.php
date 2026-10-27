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

        // Default: calendar weeks/months/years (anchor null) from the start of the current
        // one, so spending already made this period counts. An explicit start_date anchors the
        // grid there (monthly from the 15th runs 15th → 14th; a calendar boundary stays null).
        // Legacy align_to_calendar=false without a start date starts today, anchored to today.
        $length = BudgetLength::from($data['length']);
        $startDate = $data['start_date'] ?? null;

        if ($startDate === null && array_key_exists('align_to_calendar', $data) && !$data['align_to_calendar']) {
            $startDate = $user->todayDateString();
        }

        $startsAt = $startDate ?? $length->calendarStart($user->nowInTimezone())->toDateString();

        $budget = $user->budgets()->create([
            'title' => $data['title'] ?? null,
            'anchor_date' => $length->anchorFor($startDate),
        ]);

        $period = $budget->periods()->create([
            'amount' => $data['amount'],
            'length' => $data['length'],
            'starts_at' => $startsAt,
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
