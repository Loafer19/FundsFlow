<?php

namespace App\Actions\Monobank;

use App\Exceptions\MonobankException;
use App\Jobs\SyncMonobankConnectionJob;
use App\Models\MonobankConnection;
use App\Models\User;
use App\Services\Monobank\MonobankClient;
use Illuminate\Support\Str;

class ConnectMonobankAction
{
    public function __construct(
        private readonly MonobankClient $client,
        private readonly SyncMonobankAccountsAction $syncAccounts,
    ) {}

    public function execute(User $user, string $token): MonobankConnection
    {
        $token = trim($token);

        if ($token === '') {
            throw new MonobankException('Paste the personal token from api.monobank.ua.', 422);
        }

        $info = $this->client->clientInfo($token);
        $connection = $user->monobankConnection;

        if (!$connection) {
            $connection = $user->monobankConnection()->create([
                'token' => $token,
                'webhook_uuid' => (string) Str::uuid(),
                'status' => MonobankConnection::STATUS_SYNCING,
                'notify' => true,
                'last_error' => null,
            ]);
        } else {
            $connection->fill([
                'token' => $token,
                'status' => MonobankConnection::STATUS_SYNCING,
                'last_error' => null,
            ])->save();
        }

        $connection->load('accounts');
        $this->client->markThrottled($connection->id);
        $this->syncAccounts->execute($connection, $info);
        $this->client->setWebhook($token, $connection->webhookUrl(), $connection->id);

        SyncMonobankConnectionJob::dispatch($connection->id, true)->afterResponse();

        return $connection->fresh(['accounts.tag']);
    }
}
