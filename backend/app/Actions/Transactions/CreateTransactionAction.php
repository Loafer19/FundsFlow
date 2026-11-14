<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionSource;
use App\Events\TransactionsChanged;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TagIds;
use App\Support\TransactionTypeData;
use App\Support\UserAnalytics;
use Illuminate\Support\Facades\Gate;

class CreateTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data, TransactionSource $source, bool $requireAllFields = true): Transaction
    {
        Gate::forUser($user)->authorize('create', Transaction::class);

        // Type, field values and the amount a computed field fills; the type's default tags join in
        // unless the client already showed them as editable picks (default_tags=false, the web form).
        $typed = TransactionTypeData::forCreate($user, $data, $requireAllFields);
        $defaultTags = (bool) ($data['default_tags'] ?? true) ? $typed['tag_ids'] : [];
        unset($data['transaction_type_id'], $data['data'], $data['add_options'], $data['default_tags']);
        $data['amount'] = $typed['amount'];

        if ($typed['transaction_type_id'] !== null) {
            $data['transaction_type_id'] = $typed['transaction_type_id'];
            $data['data'] = $typed['data'];
        }

        $tags = TagIds::forSync($user, array_values(array_unique([...array_map('intval', (array) ($data['tags'] ?? [])), ...$defaultTags])));
        unset($data['tags']);

        $data['source'] = $source;

        $transaction = $user->transactions()->create($data);

        $transaction->tags()->attach($tags);

        UserAnalytics::firstTransaction($user);

        // Reload from DB so the response reflects stored amount (not in-memory input).
        $transaction = $transaction->fresh()->load(['tags', 'attachments', 'transactionType']);
        TransactionsChanged::dispatch((int) $user->id, 'created', [(int) $transaction->id]);

        return $transaction;
    }
}
