<?php

namespace App\Actions\Identities;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class LinkTelegramIdentityAction
{
    public function execute(User $user, string $code): Identity
    {
        $chatId = Cache::pull("telegram_link:{$code}");

        if ($chatId === null) {
            throw new RuntimeException('Код недійсний або протермінований.');
        }

        return Identity::updateOrCreate(
            ['provider' => 'telegram', 'external_id' => (string) $chatId],
            ['user_id' => $user->id],
        );
    }
}
