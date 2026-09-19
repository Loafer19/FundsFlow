<?php

namespace App\Actions\Auth;

use App\Models\Identity;
use App\Models\User;
use App\Support\TelegramWebAppInitData;

class AuthenticateTelegramWebAppAction
{
    public function __construct(
        private readonly TelegramWebAppInitData $initData,
    ) {}

    /**
     * @return array{needs_auth: true, telegram: array<string, mixed>}|array{user: User}
     */
    public function execute(string $initData): array
    {
        $parsed = $this->initData->parse($initData);
        $telegramUser = $parsed['user'];
        $externalId = (string) $telegramUser['id'];

        $identity = Identity::query()
            ->where('provider', 'telegram')
            ->where('external_id', $externalId)
            ->first();

        if (!$identity) {
            return [
                'needs_auth' => true,
                'telegram' => $telegramUser,
            ];
        }

        $identity->update([
            'meta' => $this->initData->identityMeta($telegramUser),
        ]);

        return [
            'user' => $identity->user,
        ];
    }
}
