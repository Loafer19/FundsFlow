<?php

namespace App\Actions\Monobank;

use App\Enums\BudgetLength;
use App\Enums\BudgetType;
use App\Exceptions\MonobankException;
use App\Models\Budget;
use App\Models\MonobankAccount;
use App\Models\User;

class LinkMonobankJarGoalAction
{
    public function __construct(private readonly UnlinkMonobankJarGoalAction $unlink) {}

    public function execute(User $user, MonobankAccount $jar, bool $track): Budget
    {
        if ($jar->type !== 'jar') {
            throw new MonobankException('Only monobank jars can be tracked as goals.', 422);
        }

        if ($track && !$jar->isUah()) {
            throw new MonobankException('Only UAH jars can be tracked as goals.', 422);
        }

        $existing = $user->budgets()
            ->where('monobank_jar_id', $jar->mono_account_id)
            ->first();

        if (!$track) {
            if ($existing) {
                $this->unlink->execute($existing, forget: true);
            }

            return $existing ?? new Budget;
        }

        $balance = round((float) ($jar->balance ?? 0), 2);

        if ($existing) {
            $existing->forceFill([
                'monobank_jar_balance' => $balance,
                'monobank_jar_unlinked_at' => null,
                'monobank_jar_balance_at_link' => $existing->monobank_jar_balance_at_link ?? $balance,
            ])->save();

            return $existing->fresh('periods.tags');
        }

        $byTitle = $jar->title
            ? $user->budgets()
                ->where('type', BudgetType::Goal->value)
                ->whereNull('monobank_jar_id')
                ->whereRaw('LOWER(title) = ?', [mb_strtolower($jar->title)])
                ->first()
            : null;

        if ($byTitle) {
            $byTitle->forceFill([
                'monobank_jar_id' => $jar->mono_account_id,
                'monobank_jar_balance' => $balance,
                'monobank_jar_balance_at_link' => $balance,
                'monobank_jar_unlinked_at' => null,
            ])->save();

            return $byTitle->fresh('periods.tags');
        }

        $target = round((float) ($jar->goal_amount ?? 0), 2);
        if ($target <= 0) {
            $target = max($balance, 1.0);
        }

        $budget = $user->budgets()->create([
            'title' => $jar->title ?: 'Monobank jar',
            'type' => BudgetType::Goal,
            'monobank_jar_id' => $jar->mono_account_id,
            'monobank_jar_balance' => $balance,
            'monobank_jar_balance_at_link' => $balance,
        ]);

        $budget->periods()->create([
            'amount' => $target,
            'length' => BudgetLength::Month->value,
            'starts_at' => $user->todayDateString(),
            'ends_at' => null,
        ]);

        return $budget->fresh('periods.tags');
    }
}
