<?php

namespace App\Actions\Identities;

use App\Models\User;
use App\Support\TelegramCode;

class GenerateTelegramLinkCodeAction
{
    public const CACHE_PREFIX = 'telegram_deeplink';

    public function execute(User $user): string
    {
        return TelegramCode::issue(self::CACHE_PREFIX, $user->id, now()->addMinutes(10));
    }
}
