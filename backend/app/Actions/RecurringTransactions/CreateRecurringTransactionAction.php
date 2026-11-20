<?php

namespace App\Actions\RecurringTransactions;

use App\Models\RecurringTransaction;
use App\Models\User;
use App\Support\RecurringTypeData;
use App\Support\TagIds;
use Illuminate\Support\Facades\Gate;

class CreateRecurringTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): RecurringTransaction
    {
        Gate::forUser($user)->authorize('create', RecurringTransaction::class);

        $data['next_run_at'] = $data['starts_at'];
        // A new rule is always active — set explicitly so the in-memory
        // model reflects it immediately (the DB column default doesn't
        // populate back into this instance until it's re-fetched).
        $data['active'] = true;

        $typed = RecurringTypeData::forWrite(
            $user,
            $data,
            (string) $data['frequency'],
            $data['next_run_at'],
        );
        $data['transaction_type_id'] = $typed['transaction_type_id'];
        $data['data'] = $typed['data'];

        $explicitTags = array_key_exists('tags', $data);
        $tagIds = TagIds::forSync($user, $data['tags'] ?? []);
        $applyDefaults = (bool) ($data['default_tags'] ?? !$explicitTags);
        unset($data['tags'], $data['add_options'], $data['default_tags']);

        if ($applyDefaults && $typed['transaction_type_id']) {
            $type = $user->transactionTypes()->find($typed['transaction_type_id']);
            if ($type) {
                $tagIds = TagIds::forSync($user, array_values(array_unique([
                    ...$tagIds,
                    ...$type->defaultTagIds(),
                ])));
            }
        }

        $rule = $user->recurringTransactions()->create($data);
        $rule->tags()->sync($tagIds);

        return $rule->load(['tags', 'transactionType']);
    }
}
