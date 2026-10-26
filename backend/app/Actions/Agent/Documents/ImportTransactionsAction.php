<?php

namespace App\Actions\Agent\Documents;

use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Tags\CreateTagAction;
use App\Actions\Transactions\CreateTransactionsAction;
use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Confirm of a bulk import: every row is saved in ONE database transaction (all or nothing).
 * The payload is re-checked here because it was built before the Confirm tap. Tags the user
 * agreed to create (payload create_tags, rows' new_tags) are created in the same transaction.
 */
final class ImportTransactionsAction
{
    public function __construct(
        private readonly CreateTransactionsAction $createTransactions,
        private readonly CreateTagAction $createTag,
    ) {}

    /** Tags one import may create. */
    private const MAX_NEW_TAGS = 50;

    /**
     * @param array<string, mixed> $payload
     * @return Collection<int, Transaction>
     */
    public function apply(User $user, array $payload, TransactionSource $source): Collection
    {
        return DB::transaction(fn (): Collection => $this->applyInTransaction($user, $payload, $source));
    }

    /**
     * @param array<string, mixed> $payload
     * @return Collection<int, Transaction>
     */
    private function applyInTransaction(User $user, array $payload, TransactionSource $source): Collection
    {
        $rows = is_array($payload['transactions'] ?? null) ? array_slice($payload['transactions'], 0, DocumentFile::maxRows()) : [];
        $ownedTags = $user->tags()->pluck('id')->map(static fn ($id) => (int) $id)->flip()->all();

        // New tags: an existing one with the same title (created meanwhile) is reused.
        $byTitle = $user->tags()->get(['id', 'title'])->mapWithKeys(static fn ($tag) => [mb_strtolower(trim((string) $tag->title)) => (int) $tag->id])->all();
        $wanted = [];
        foreach (is_array($payload['create_tags'] ?? null) ? $payload['create_tags'] : [] as $title) {
            if (is_string($title) && trim($title) !== '') {
                $wanted[mb_strtolower(trim($title))] ??= mb_substr(trim($title), 0, 60);
            }
        }
        $newIds = [];
        foreach (array_slice($wanted, 0, self::MAX_NEW_TAGS, true) as $lower => $title) {
            if (!isset($byTitle[$lower])) {
                $byTitle[$lower] = (int) $this->createTag->execute($user, [
                    'title' => $title,
                    'emoji' => AgentToolExecutor::DEFAULT_TAG_EMOJI,
                    'parent_id' => null,
                    'calc_balance' => true,
                ])->id;
            }
            $newIds[$lower] = $byTitle[$lower];
            $ownedTags[$byTitle[$lower]] = true;
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['amount'] ?? null) || (float) $row['amount'] == 0.0) {
                continue;
            }
            $at = (string) ($row['at'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $at)) {
                continue;
            }
            $tags = array_values(array_filter(
                array_map('intval', is_array($row['tag_ids'] ?? null) ? $row['tag_ids'] : []),
                static fn (int $id) => isset($ownedTags[$id]),
            ));
            foreach (is_array($row['new_tags'] ?? null) ? $row['new_tags'] : [] as $title) {
                $id = is_string($title) ? ($newIds[mb_strtolower(trim($title))] ?? null) : null;
                if ($id !== null && !in_array($id, $tags, true)) {
                    $tags[] = $id;
                }
            }
            $items[] = [
                'amount' => round((float) $row['amount'], 2),
                'at' => $at,
                'note' => is_string($row['note'] ?? null) && trim($row['note']) !== '' ? mb_substr(trim($row['note']), 0, 255) : null,
                'tags' => $tags,
            ];
        }

        return $items === [] ? collect() : $this->createTransactions->execute($user, $items, $source);
    }

    /**
     * "✅ Imported 42 transactions from bank.csv".
     *
     * @param Collection<int, Transaction> $created
     */
    public static function doneMessage(Collection $created, array $payload): string
    {
        $n = $created->count();
        $source = is_string($payload['source'] ?? null) ? $payload['source'] : 'the file';
        $new = array_values(array_filter(is_array($payload['create_tags'] ?? null) ? $payload['create_tags'] : [], 'is_string'));

        return $n === 0
            ? 'Nothing was imported.'
            : "✅ Imported {$n} transaction" . ($n === 1 ? '' : 's') . " from {$source}."
                . ($new !== [] ? ' New tags: ' . implode(', ', array_slice($new, 0, 20)) . '.' : '');
    }
}
