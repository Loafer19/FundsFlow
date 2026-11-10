<?php

namespace App\Actions\Monobank;

use App\Jobs\RefreshMonobankJarsJob;
use App\Models\MonobankConnection;

class ProcessMonobankWebhookAction
{
    public function __construct(private readonly ImportStatementItemAction $importItem) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(int $connectionId, array $payload): void
    {
        $type = $payload['type'] ?? '';

        if ($type !== 'StatementItem') {
            return;
        }

        $data = $payload['data'] ?? [];
        $accountId = isset($data['account']) ? (string) $data['account'] : '';
        $item = $data['statementItem'] ?? null;

        if ($accountId === '' || !is_array($item)) {
            return;
        }

        $connection = MonobankConnection::query()->with(['user', 'accounts'])->find($connectionId);

        if (!$connection || !$connection->user) {
            return;
        }

        $hasLinkedJars = $connection->user->budgets()
            ->whereNotNull('monobank_jar_id')
            ->whereNull('monobank_jar_unlinked_at')
            ->exists();

        $account = $connection->accounts->firstWhere('mono_account_id', $accountId);

        if (!$account || $account->isJar() || !$account->enabled) {
            if ($hasLinkedJars || ($account && $account->isJar())) {
                $this->scheduleJarRefresh($connection->id);
            }

            return;
        }

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

        $result = $this->importItem->execute(
            $connection->user,
            $connection,
            $account,
            $item,
            notify: true,
            ownIbans: $ownIbans,
            jarTitles: $jarTitles,
        );

        if ($result['status'] === 'created') {
            $connection->forceFill([
                'status' => MonobankConnection::STATUS_ACTIVE,
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        }

        if ($hasLinkedJars) {
            $this->scheduleJarRefresh($connection->id);
        }
    }

    private function scheduleJarRefresh(int $connectionId): void
    {
        RefreshMonobankJarsJob::dispatch($connectionId)->delay(now()->addSeconds(65));
    }
}
