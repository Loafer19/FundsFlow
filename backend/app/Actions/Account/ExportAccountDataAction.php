<?php

namespace App\Actions\Account;

use App\Http\Resources\BudgetResource;
use App\Http\Resources\RecurringTransactionResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionTypeResource;
use App\Models\User;

class ExportAccountDataAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        $user->load([
            'identities',
            'transactions.tags',
            'transactions.attachments',
            'budgets.periods.tags',
            'recurringTransactions.tags',
        ]);


        // Include soft-deleted tags too — they still appear on historical transactions.
        $tags = $user->tags()->withTrashed()->orderBy('id')->get();

        return [
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'preferences' => $user->resolvedPreferences(),
                'identities' => $user->identities->map(fn ($identity) => [
                    'provider' => $identity->provider,
                    'external_id' => $identity->external_id,
                    'meta' => $identity->meta,
                ])->values()->all(),
            ],
            'tags' => $tags->map(fn ($tag) => [
                ...((new TagResource($tag))->resolve()),
                'deleted_at' => $tag->deleted_at?->toIso8601String(),
            ])->values()->all(),
            // Types with their field schema (hidden fields too); transactions carry transaction_type_id + data.
            'transaction_types' => TransactionTypeResource::collection(
                $user->transactionTypes()->withTrashed()->orderBy('id')->get(),
            )->resolve(),
            'transactions' => TransactionResource::collection(
                $user->transactions->sortByDesc('at')->values(),
            )->resolve(),
            'budgets' => BudgetResource::collection($user->budgets)->resolve(),
            'recurring_transactions' => RecurringTransactionResource::collection(
                $user->recurringTransactions,
            )->resolve(),
        ];
    }
}
