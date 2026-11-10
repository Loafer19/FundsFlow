<?php

namespace App\Actions\Monobank;

use App\Models\MonobankAccount;
use App\Models\MonobankConnection;

class SyncMonobankAccountsAction
{
    public function __construct(private readonly UnlinkMonobankJarGoalAction $unlink) {}

    /**
     * @param  array<string, mixed>  $clientInfo
     * @return list<MonobankAccount>
     */
    public function execute(MonobankConnection $connection, array $clientInfo): array
    {
        $connection->loadMissing(['user', 'accounts']);

        $seenCards = [];
        $seenJars = [];
        $uah = (int) config('monobank.uah_currency_code', 980);

        foreach ($clientInfo['accounts'] ?? [] as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }

            $seenCards[] = (string) $row['id'];
            $currency = (int) ($row['currencyCode'] ?? $uah);
            $masked = $row['maskedPan'][0] ?? null;

            $connection->accounts()->updateOrCreate(
                ['mono_account_id' => (string) $row['id']],
                [
                    'currency_code' => $currency,
                    'type' => (string) ($row['type'] ?? 'black'),
                    'masked_pan' => is_string($masked) ? $masked : null,
                    'iban' => isset($row['iban']) ? (string) $row['iban'] : null,
                    'title' => null,
                    'balance' => null,
                    'goal_amount' => null,
                    'enabled' => $this->defaultEnabled($connection, (string) $row['id'], $currency === $uah),
                ],
            );
        }

        $jarsProvided = array_key_exists('jars', $clientInfo);

        if ($jarsProvided) {
            foreach ($clientInfo['jars'] ?? [] as $row) {
                if (!is_array($row) || empty($row['id'])) {
                    continue;
                }

                $seenJars[] = (string) $row['id'];
                $currency = (int) ($row['currencyCode'] ?? $uah);
                $balance = MonobankAccount::fromMinor($row['balance'] ?? 0);
                $goal = array_key_exists('goal', $row) ? MonobankAccount::fromMinor($row['goal']) : null;

                if ($goal !== null && $goal <= 0) {
                    $goal = null;
                }

                $connection->accounts()->updateOrCreate(
                    ['mono_account_id' => (string) $row['id']],
                    [
                        'currency_code' => $currency,
                        'type' => 'jar',
                        'masked_pan' => null,
                        'iban' => null,
                        'title' => isset($row['title']) ? (string) $row['title'] : null,
                        'balance' => $balance,
                        'goal_amount' => $goal,
                        'enabled' => false,
                    ],
                );

                $user = $connection->user;

                if ($user) {
                    $user->budgets()
                        ->where('monobank_jar_id', (string) $row['id'])
                        ->whereNull('monobank_jar_unlinked_at')
                        ->update(['monobank_jar_balance' => $balance]);
                }
            }
        }

        $seen = array_merge($seenCards, $seenJars);

        if ($seenCards !== [] || $jarsProvided) {
            $query = $connection->accounts()->whereNotIn('mono_account_id', $seen);

            if (!$jarsProvided) {
                $query->where('type', '!=', 'jar');
            }

            foreach ($query->get() as $account) {
                if ($account->isJar() && $connection->user) {
                    $connection->user->budgets()
                        ->where('monobank_jar_id', $account->mono_account_id)
                        ->get()
                        ->each(fn ($budget) => $this->unlink->execute($budget, forget: false));
                }

                $account->delete();
            }
        }

        return $connection->accounts()->orderBy('id')->get()->all();
    }

    private function defaultEnabled(MonobankConnection $connection, string $monoAccountId, bool $isUah): bool
    {
        $existing = $connection->accounts->firstWhere('mono_account_id', $monoAccountId);

        if ($existing) {
            return (bool) $existing->enabled && !$existing->isJar();
        }

        return $isUah;
    }
}
