<?php

namespace App\Actions\Monobank;

use App\Models\User;
use App\Services\Monobank\MonobankClient;

class DisconnectMonobankAction
{
    public function __construct(
        private readonly MonobankClient $client,
        private readonly UnlinkMonobankJarGoalAction $unlink,
    ) {}

    public function execute(User $user): void
    {
        $connection = $user->monobankConnection;

        if (!$connection) {
            return;
        }

        $this->unlink->forUser($user, forget: false);

        try {
            $this->client->setWebhook($connection->token, '', $connection->id);
        } catch (\Throwable) {
            // Token may already be revoked; still drop the local copy.
        }

        $connection->delete();
    }
}
