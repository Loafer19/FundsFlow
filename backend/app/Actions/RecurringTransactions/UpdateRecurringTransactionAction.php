<?php

namespace App\Actions\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateRecurringTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, RecurringTransaction $recurringTransaction, array $data): RecurringTransaction
    {
        Gate::forUser($user)->authorize('update', $recurringTransaction);

        $tagIds = $data['tags'] ?? [];
        unset($data['tags']);

        // Resuming a paused rule starts fresh from today instead of
        // materializing every occurrence missed while it was inactive.
        $resuming = ($data['active'] ?? false) && !$recurringTransaction->active;

        if ($resuming) {
            $data['next_run_at'] = now()->toDateString();
        }

        $recurringTransaction->update($data);
        $recurringTransaction->tags()->sync($tagIds);

        return $recurringTransaction->load('tags');
    }
}
