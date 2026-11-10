<?php

namespace App\Actions\Monobank;

use App\Actions\Transactions\CreateTransactionAction;
use App\Actions\Transactions\UpdateTransactionAction;
use App\Enums\TransactionSource;
use App\Models\MonobankAccount;
use App\Models\MonobankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Monobank\TransferDetector;
use Carbon\Carbon;

class ImportStatementItemAction
{
    public function __construct(
        private readonly CreateTransactionAction $createTransaction,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly ResolveMonobankTagsAction $resolveTags,
        private readonly NotifyMonobankImportAction $notifyImport,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     * @return array{status: string, transaction: ?Transaction}
     */
    public function execute(
        User $user,
        MonobankConnection $connection,
        MonobankAccount $account,
        array $item,
        bool $notify,
        array $ownIbans,
        array $jarTitles,
        ?int $skipNewBefore = null,
    ): array {
        $externalId = isset($item['id']) ? (string) $item['id'] : '';

        if ($externalId === '') {
            return ['status' => 'skipped', 'transaction' => null];
        }

        if ($account->isJar() || !$account->isUah()) {
            return ['status' => 'skipped', 'transaction' => null];
        }

        if (TransferDetector::isInternal($item, $ownIbans, $jarTitles)) {
            return ['status' => 'skipped', 'transaction' => null];
        }

        $amount = round(((int) ($item['amount'] ?? 0)) / 100, 2);

        if ($amount == 0.0) {
            return ['status' => 'skipped', 'transaction' => null];
        }

        $note = $this->noteFrom($item);
        $existing = Transaction::query()
            ->where('user_id', $user->id)
            ->where('external_source', 'monobank')
            ->where('external_id', $externalId)
            ->first();

        if ($existing) {
            return $this->refreshExisting($user, $existing, $item, $amount);
        }

        if (!isset($item['time'])) {
            return ['status' => 'skipped', 'transaction' => null];
        }

        // Older than what we already fetched and not in the ledger: the user deleted it.
        if ($skipNewBefore !== null && (int) $item['time'] < $skipNewBefore) {
            return ['status' => 'skipped', 'transaction' => null];
        }

        $mcc = isset($item['mcc']) ? (int) $item['mcc'] : null;
        $tags = $this->resolveTags->execute($user, $note, $mcc, $account->tag_id);
        $at = Carbon::createFromTimestamp((int) $item['time'], $user->timezone())->format('Y-m-d H:i:s');

        $transaction = $this->createTransaction->execute($user, [
            'at' => $at,
            'amount' => $amount,
            'note' => $note,
            'tags' => $tags,
            'external_source' => 'monobank',
            'external_id' => $externalId,
            'external_meta' => $this->metaFrom($item, $account->mono_account_id),
        ], TransactionSource::Monobank);

        if ($notify) {
            $this->notifyImport->execute($user, $transaction, $connection);
        }

        return ['status' => 'created', 'transaction' => $transaction];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{status: string, transaction: Transaction}
     */
    private function refreshExisting(User $user, Transaction $existing, array $item, float $amount): array
    {
        $meta = $existing->external_meta ?? [];
        $meta['hold'] = (bool) ($item['hold'] ?? false);
        $changed = abs((float) $existing->amount - $amount) >= 0.005;

        if ($changed) {
            $this->updateTransaction->execute($user, $existing, ['amount' => $amount]);
        }

        $existing->external_meta = $meta;
        $existing->save();

        return [
            'status' => $changed ? 'updated' : 'duplicate',
            'transaction' => $existing->fresh()->load(['tags', 'attachments']),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function noteFrom(array $item): string
    {
        $parts = [];
        $description = trim((string) ($item['description'] ?? ''));

        if ($description !== '') {
            $parts[] = $description;
        }

        $comment = trim((string) ($item['comment'] ?? ''));

        if ($comment !== '' && $comment !== $description) {
            $parts[] = $comment;
        }

        if ($parts === []) {
            $counter = trim((string) ($item['counterName'] ?? ''));

            if ($counter !== '') {
                $parts[] = $counter;
            }
        }

        $note = mb_substr(implode(' · ', $parts), 0, 255);

        return $note !== '' ? $note : 'Monobank';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function metaFrom(array $item, string $accountId): array
    {
        return [
            'mcc' => isset($item['mcc']) ? (int) $item['mcc'] : null,
            'hold' => (bool) ($item['hold'] ?? false),
            'account' => $accountId,
            'currencyCode' => $item['currencyCode'] ?? null,
            'counterIban' => $item['counterIban'] ?? null,
            'counterName' => $item['counterName'] ?? null,
        ];
    }
}
