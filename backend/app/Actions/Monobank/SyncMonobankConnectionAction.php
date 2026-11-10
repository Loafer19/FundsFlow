<?php

namespace App\Actions\Monobank;

use App\Exceptions\MonobankException;
use App\Models\MonobankAccount;
use App\Models\MonobankConnection;
use App\Services\Monobank\MonobankClient;

class SyncMonobankConnectionAction
{
    public function __construct(
        private readonly MonobankClient $client,
        private readonly ImportStatementItemAction $importItem,
        private readonly SyncMonobankAccountsAction $syncAccounts,
    ) {}

    public function execute(int $connectionId, bool $full = false): void
    {
        $connection = MonobankConnection::query()->with(['user', 'accounts'])->find($connectionId);

        if (!$connection || !$connection->user) {
            return;
        }

        $connection->forceFill([
            'status' => MonobankConnection::STATUS_SYNCING,
            'last_error' => null,
        ])->save();

        try {
            $this->client->throttle($connection->id);
            $info = $this->client->clientInfo($connection->token, $connection->id);
            $this->syncAccounts->execute($connection, $info);
            $connection->load('accounts');

            $ownIbans = $connection->accounts
                ->pluck('iban')
                ->filter()
                ->map(fn ($iban) => strtoupper(preg_replace('/\s+/', '', (string) $iban) ?? ''))
                ->values()
                ->all();
            $jarTitles = $connection->accounts
                ->where('type', 'jar')
                ->pluck('title')
                ->filter()
                ->values()
                ->all();

            $now = time();
            $maxWindow = (int) config('monobank.statement_max_seconds', 2682000);
            $from = $full || !$connection->last_synced_at
                ? $now - $maxWindow
                : max($now - $maxWindow, $connection->last_synced_at->timestamp - 3600);

            foreach ($connection->accounts->where('enabled', true) as $account) {
                if ($account->isJar() || !$account->isUah()) {
                    continue;
                }

                $this->importAccount($connection, $account, $from, $now, $ownIbans, $jarTitles);
            }

            $connection->forceFill([
                'status' => MonobankConnection::STATUS_ACTIVE,
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (MonobankException $e) {
            $connection->forceFill([
                'status' => MonobankConnection::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        } catch (\Throwable $e) {
            $connection->forceFill([
                'status' => MonobankConnection::STATUS_ERROR,
                'last_error' => "Couldn't sync Monobank. Try again in a moment.",
            ])->save();

            report($e);
        }
    }

    /**
     * @param  list<string>  $ownIbans
     * @param  list<string>  $jarTitles
     */
    private function importAccount(
        MonobankConnection $connection,
        MonobankAccount $account,
        int $from,
        int $to,
        array $ownIbans,
        array $jarTitles,
    ): void {
        $windowTo = $to;

        while ($windowTo >= $from) {
            $this->client->throttle($connection->id);
            $items = $this->client->statement(
                $connection->token,
                $account->mono_account_id,
                $from,
                $windowTo,
                $connection->id,
            );

            foreach ($items as $item) {
                $this->importItem->execute(
                    $connection->user,
                    $connection,
                    $account,
                    $item,
                    notify: false,
                    ownIbans: $ownIbans,
                    jarTitles: $jarTitles,
                );
            }

            if (count($items) < 500) {
                break;
            }

            $oldest = min(array_map(fn (array $item) => (int) ($item['time'] ?? $from), $items));
            $windowTo = $oldest - 1;
        }
    }
}
