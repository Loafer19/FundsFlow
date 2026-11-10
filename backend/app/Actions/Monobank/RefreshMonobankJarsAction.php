<?php

namespace App\Actions\Monobank;

use App\Exceptions\MonobankException;
use App\Models\MonobankConnection;
use App\Services\Monobank\MonobankClient;

class RefreshMonobankJarsAction
{
    public function __construct(
        private readonly MonobankClient $client,
        private readonly SyncMonobankAccountsAction $syncAccounts,
    ) {}

    public function execute(int $connectionId): void
    {
        $connection = MonobankConnection::query()->with(['user', 'accounts'])->find($connectionId);

        if (!$connection || !$connection->user || $connection->token === null || $connection->token === '') {
            return;
        }

        try {
            $this->client->throttle($connection->id);
            $info = $this->client->clientInfo($connection->token, $connection->id);
            $this->syncAccounts->execute($connection, $info);
        } catch (MonobankException $e) {
            if ($e->status === 429) {
                return;
            }

            report($e);
        }
    }
}
