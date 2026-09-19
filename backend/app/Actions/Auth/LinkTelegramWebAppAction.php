<?php

namespace App\Actions\Auth;

use App\Models\Identity;
use App\Models\User;
use App\Support\TelegramWebAppInitData;
use Illuminate\Validation\ValidationException;

class LinkTelegramWebAppAction
{
    public function __construct(
        private readonly TelegramWebAppInitData $initData,
    ) {}

    public function execute(User $user, string $initData): User
    {
        $parsed = $this->initData->parse($initData);
        $telegramUser = $parsed['user'];
        $externalId = (string) $telegramUser['id'];
        $meta = $this->initData->identityMeta($telegramUser);

        $identity = Identity::query()
            ->where('provider', 'telegram')
            ->where('external_id', $externalId)
            ->first();

        if ($identity) {
            if ((int) $identity->user_id === (int) $user->id) {
                $identity->update(['meta' => $meta]);

                return $user->load('identities');
            }

            throw ValidationException::withMessages([
                'initData' => ['This Telegram account is already linked to another FundsFlow user.'],
            ]);
        }

        $user->identities()->create([
            'provider' => 'telegram',
            'external_id' => $externalId,
            'meta' => $meta,
        ]);

        return $user->load('identities');
    }
}
