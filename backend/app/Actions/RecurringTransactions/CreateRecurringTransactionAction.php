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

        $rule = $user->recurringTransactions()->create($data);
        $rule->tags()->sync($tagIds);

        return $rule->load('tags');
    }
}
