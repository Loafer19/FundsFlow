<?php

namespace App\Actions\Identities;

use App\Channels\Telegram\TelegramBot;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class LinkTelegramIdentityAction
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function execute(User $user, string $code): Identity
    {
        $chatId = Cache::pull("telegram_link:{$code}");

        if ($chatId === null) {
            throw new RuntimeException('This code is invalid or has expired.');
        }

        // A fresh code proves ownership of the chat — reassign it if it was
        // previously linked to a different user, instead of hitting the
        // unique(provider, external_id) constraint.
        Identity::query()
            ->where('provider', 'telegram')
            ->where('external_id', (string) $chatId)
            ->where('user_id', '!=', $user->id)
            ->delete();

        $identity = $user->identities()->updateOrCreate(
            ['provider' => 'telegram', 'external_id' => (string) $chatId],
            [],
        );

        $this->bot->sendWelcome($chatId);

        return $identity;
    }
}
