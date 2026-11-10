<?php

namespace App\Actions\Monobank;

use App\Enums\TransactionSource;
use App\Models\MonobankTagRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Monobank\MerchantNormalizer;

class LearnMonobankTagRuleAction
{
    public function execute(User $user, Transaction $transaction): void
    {
        $isImported = $transaction->external_source === 'monobank'
            || $transaction->source === TransactionSource::Monobank;

        if (!$isImported) {
            return;
        }

        $user->loadMissing('monobankConnection.accounts');

        $key = MerchantNormalizer::normalize($transaction->note);

        if ($key === null) {
            return;
        }

        $exclude = $user->monobankConnection?->accounts
            ?->pluck('tag_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all() ?? [];

        $transaction->loadMissing('tags');
        $tag = $transaction->tags->first(fn ($item) => !in_array((int) $item->id, $exclude, true));

        if (!$tag) {
            MonobankTagRule::query()
                ->where('user_id', $user->id)
                ->where('merchant_key', $key)
                ->delete();

            return;
        }

        $mcc = $transaction->external_meta['mcc'] ?? null;

        MonobankTagRule::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'merchant_key' => $key,
            ],
            [
                'mcc' => is_numeric($mcc) ? (int) $mcc : null,
                'tag_id' => $tag->id,
            ],
        );
    }
}
