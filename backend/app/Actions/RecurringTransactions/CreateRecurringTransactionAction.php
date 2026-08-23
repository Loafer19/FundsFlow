<?php

namespace App\Actions\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateRecurringTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): RecurringTransaction
    {
        Gate::forUser($user)->authorize('create', RecurringTransaction::class);

        $tagIds = $data['tags'] ?? [];
        unset($data['tags']);

        $data['next_run_at'] = $data['starts_at'];
        // A new rule is always active — set explicitly so the in-memory
        // model reflects it immediately (the DB column default doesn't
        // populate back into this instance until it's re-fetched).
        $data['active'] = true;

        $rule = $user->recurringTransactions()->create($data);
        $rule->tags()->sync($tagIds);

        return $rule->load('tags');
    }
}
