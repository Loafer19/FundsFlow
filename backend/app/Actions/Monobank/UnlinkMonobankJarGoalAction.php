<?php

namespace App\Actions\Monobank;

use App\Models\Budget;
use App\Models\User;

class UnlinkMonobankJarGoalAction
{
    /**
     * $forget: the user turned tracking off — keep the goal as a regular one,
     * seeded with the last jar balance so the number does not drop to zero.
     * Otherwise the jar disappeared (or Monobank was disconnected): keep using
     * the cached balance and show the goal as an unlinked jar.
     */
    public function execute(Budget $budget, bool $forget = false): void
    {
        if (!$budget->monobank_jar_id) {
            return;
        }

        if ($forget) {
            $budget->forceFill([
                'start_amount' => $budget->monobank_jar_balance ?? $budget->start_amount,
                'monobank_jar_id' => null,
                'monobank_jar_balance' => null,
                'monobank_jar_balance_at_link' => null,
                'monobank_jar_unlinked_at' => null,
            ])->save();

            return;
        }

        if ($budget->monobank_jar_unlinked_at !== null) {
            return;
        }

        $budget->forceFill([
            'monobank_jar_unlinked_at' => now(),
        ])->save();
    }

    public function forUser(User $user, bool $forget = false): void
    {
        $user->budgets()
            ->whereNotNull('monobank_jar_id')
            ->whereNull('monobank_jar_unlinked_at')
            ->get()
            ->each(fn (Budget $budget) => $this->execute($budget, $forget));
    }
}
