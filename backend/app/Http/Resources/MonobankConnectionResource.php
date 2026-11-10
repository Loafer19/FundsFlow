<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonobankConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accounts = $this->relationLoaded('accounts')
            ? $this->accounts
            : $this->accounts()->orderBy('id')->get();

        $cards = $accounts->filter(fn ($account) => !$account->isJar())->values();
        $jars = $accounts->filter(fn ($account) => $account->isJar())->values();

        $user = $this->relationLoaded('user') ? $this->user : $this->resource->user;
        $linkedJarIds = $user
            ? $user->budgets()
                ->whereNotNull('monobank_jar_id')
                ->whereNull('monobank_jar_unlinked_at')
                ->pluck('monobank_jar_id')
                ->all()
            : [];

        return [
            'connected' => true,
            'status' => $this->status,
            'notify' => (bool) $this->notify,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'accounts' => $cards->map(fn ($account) => [
                'id' => $account->id,
                'label' => $account->label(),
                'type' => $account->type,
                'type_label' => $account->typeLabel(),
                'currency' => $account->currencyCode(),
                'currency_code' => $account->currency_code,
                'masked_pan' => $account->masked_pan,
                'title' => $account->title,
                'enabled' => (bool) $account->enabled,
                'syncable' => $account->isUah(),
                'tag_id' => $account->tag_id,
            ])->values()->all(),
            'jars' => $jars->map(fn ($jar) => [
                'id' => $jar->id,
                'label' => $jar->title ?: 'Jar',
                'title' => $jar->title,
                'currency' => $jar->currencyCode(),
                'currency_code' => $jar->currency_code,
                'syncable' => $jar->isUah(),
                'balance' => $jar->balance === null ? null : (float) $jar->balance,
                'goal_amount' => $jar->goal_amount === null ? null : (float) $jar->goal_amount,
                'track_as_goal' => in_array($jar->mono_account_id, $linkedJarIds, true),
            ])->values()->all(),
        ];
    }
}
