<?php

namespace App\Actions\Identities;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class GenerateTelegramLinkCodeAction
{
    public function execute(User $user): string
    {
        $code = (string) random_int(100000, 999999);

        Cache::put("telegram_deeplink:{$code}", $user->id, now()->addMinutes(10));

        return $code;
    }
}
