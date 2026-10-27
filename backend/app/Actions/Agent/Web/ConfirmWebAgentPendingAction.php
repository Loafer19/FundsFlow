<?php

namespace App\Actions\Agent\Web;

use App\Actions\Agent\AgentPlanningTools;
use App\Actions\Agent\Documents\ImportTransactionsAction;
use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Transactions\StoreTransactionAttachmentAction;
use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionAttachmentRules;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies or cancels the staged change behind a web Confirm/Cancel card.
 * The pending id is a one-shot nonce (claimed via Cache::add), so double
 * clicks / replays never apply twice.
 */
final class ConfirmWebAgentPendingAction
{
    public const OUTDATED = 'This preview is outdated or was already handled. Ask again to get a fresh one.';

    public function __construct(
        private readonly AgentToolExecutor $tools,
        private readonly WebAgentUploads $uploads,
        private readonly StoreTransactionAttachmentAction $storeAttachment,
        private readonly ImportTransactionsAction $import,
    ) {}

    /**
     * @return array{ok: bool, status: 'applied'|'cancelled'|'outdated'|'failed', message: string, item: array<string, mixed>|null, refresh: bool}
     */
    public function execute(User $user, string $pendingId, string $action): array
    {
        $session = new WebAgentSession($user);
        $pending = $session->get()['pending'];

        if (
            !is_array($pending)
            || ($pending['type'] ?? null) !== 'agent_mutation'
            || !is_string($pending['nonce'] ?? null)
            || !hash_equals($pending['nonce'], $pendingId)
            || !Cache::add('web_agent_confirm_claimed:' . $user->id . ':' . $pendingId, true, now()->addDay())
        ) {
            $this->markCard($session, $pendingId, 'outdated');

            return ['ok' => false, 'status' => 'outdated', 'message' => self::OUTDATED, 'item' => null, 'refresh' => false];
        }

        $session->setPending(null);
        // Receipt photos are only needed until the card is resolved (copied as attachments on confirm).
        $photos = WebAgentUploads::refsIn($pending);

        if ($action === 'cancel') {
            $this->uploads->delete($user, $photos);
            $this->markCard($session, $pendingId, 'cancelled');
            $session->setSummary('Agent mutation cancelled.');

            return $this->result($session, true, 'cancelled', 'Cancelled.', false);
        }

        $kind = (string) ($pending['action'] ?? '');
        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];

        try {
            [$ok, $message, $summary] = match ($kind) {
                'create_tags' => $this->createTags($user, $session, $payload),
                'rename_tags' => $this->renameTags($user, $session, $payload),
                'create_transaction' => $this->createTransaction($user, $payload),
                'create_transactions' => $this->createTransactions($user, $payload),
                'import_transactions' => $this->importTransactions($user, $payload),
                'update_transaction' => $this->updateTransaction($user, $payload),
                'delete_transaction' => $this->deleteTransaction($user, $payload),
                'create_budget' => $this->createBudget($user, $payload),
                default => AgentPlanningTools::isStaging($kind)
                    ? $this->planning($user, $kind, $payload)
                    : [false, 'Nothing to confirm.', 'Nothing to confirm.'],
            };
        } catch (Throwable $e) {
            Log::warning('ConfirmWebAgentPendingAction: apply failed', [
                'action' => $kind,
                'message' => $e->getMessage(),
            ]);
            [$ok, $message, $summary] = [false, 'Could not apply that change; nothing was saved.', 'Agent mutation failed.'];
        } finally {
            $this->uploads->delete($user, $photos);
        }

        $this->markCard($session, $pendingId, $ok ? 'confirmed' : 'failed');
        $session->setSummary($summary);

        return $this->result($session, $ok, $ok ? 'applied' : 'failed', $message, $ok);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function createTags(User $user, WebAgentSession $session, array $payload): array
    {
        $titles = is_array($payload['titles'] ?? null) ? array_values(array_filter($payload['titles'], 'is_string')) : [];
        $titles = $this->tools->filterNewTagTitles($user, $titles);
        if ($titles === []) {
            return [false, 'Those tags already exist (or none were left to create).', 'No new tags were created.'];
        }

        $created = $this->tools->createTags($user, $titles);
        $this->tools->saveTagListSession($user, $session);
        $summary = '✅ Created tags: ' . implode(', ', $created);

        return [true, $summary, $summary];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function renameTags(User $user, WebAgentSession $session, array $payload): array
    {
        $proposals = $this->tools->normalizeRenameProposals($payload['proposals'] ?? null);
        if ($proposals === []) {
            return [false, 'The rename preview is stale or has no valid changes.', 'Rename preview had no valid changes.'];
        }

        try {
            $this->tools->renameTags($user, $proposals);
        } catch (Throwable) {
            return [false, 'Could not apply the rename preview; no changes were saved.', 'Rename failed because a tag changed or could not be updated.'];
        }

        $this->tools->saveTagListSession($user, $session);
        $lines = implode("\n", array_map(
            static fn (array $p): string => $p['before'] . ' → ' . $p['after'],
            $proposals,
        ));

        return [true, "✅ Renamed tags:\n" . $lines, 'Renamed tags: ' . str_replace("\n", '; ', $lines)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function createTransaction(User $user, array $payload): array
    {
        $tx = DB::transaction(function () use ($user, $payload): Transaction {
            $tx = $this->tools->applyCreateTransaction($user, $payload, TransactionSource::Web);
            $this->attachPhotos($user, $tx, $payload['files'] ?? []);

            return $tx;
        });

        return [true, $this->txLine($user, '✅ Saved', $tx) . $this->photoSuffix($tx), 'Transaction created via agent confirm.'];
    }

    /**
     * Several receipts at once ("Separate" photos): all or nothing.
     *
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function createTransactions(User $user, array $payload): array
    {
        $rows = array_values(array_filter(is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [], 'is_array'));
        if ($rows === []) {
            return [false, 'Nothing to confirm.', 'Nothing to confirm.'];
        }

        $created = DB::transaction(function () use ($user, $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $tx = $this->tools->applyCreateTransaction($user, $row, TransactionSource::Web);
                $this->attachPhotos($user, $tx, $row['files'] ?? []);
                $out[] = $tx;
            }

            return $out;
        });

        $lines = array_map(fn (Transaction $tx): string => '• ' . substr($this->txLine($user, '•', $tx), strlen('•: ')) . $this->photoSuffix($tx), $created);

        return [true, '✅ Saved ' . count($created) . " transactions:\n" . implode("\n", $lines), 'Transactions created via agent confirm.'];
    }

    /**
     * Bulk import from a document (CSV / Excel / statement): one database transaction.
     *
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function importTransactions(User $user, array $payload): array
    {
        $created = $this->import->apply($user, $payload, TransactionSource::Web);
        if ($created->isEmpty()) {
            return [false, 'Nothing to import.', 'Import had no valid rows.'];
        }
        $message = ImportTransactionsAction::doneMessage($created, $payload);

        return [true, $message, $message];
    }

    /**
     * Copy staged receipt photos onto the transaction (same rules as any attachment).
     */
    private function attachPhotos(User $user, Transaction $tx, mixed $refs): void
    {
        foreach (is_array($refs) ? $refs : [] as $ref) {
            // A Word file is read for its text only; it is not an attachment type.
            if (!is_array($ref) || !isset(TransactionAttachmentRules::ALLOWED_MIMES[(string) ($ref['mime'] ?? '')])) {
                continue;
            }
            $contents = $this->uploads->read($user, $ref);
            if ($contents === null) {
                continue; // expired / pruned: save the transaction without it
            }
            $this->storeAttachment->execute($user, $tx, [
                'name' => (string) ($ref['name'] ?? 'receipt'),
                'mime' => (string) ($ref['mime'] ?? ''),
                'contents' => $contents,
            ]);
        }
    }

    private function photoSuffix(Transaction $tx): string
    {
        $count = $tx->attachments()->count();

        return $count > 0 ? ' · 📎 ' . $count : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function updateTransaction(User $user, array $payload): array
    {
        $tx = $this->tools->applyUpdateTransaction($user, $payload);
        if (!$tx) {
            return [false, 'That transaction no longer exists.', 'Transaction to update no longer exists.'];
        }

        return [true, $this->txLine($user, '✅ Updated', $tx), 'Transaction updated via agent confirm.'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function deleteTransaction(User $user, array $payload): array
    {
        if (!$this->tools->applyDeleteTransaction($user, $payload)) {
            return [false, 'That transaction no longer exists.', 'Transaction to delete no longer exists.'];
        }

        return [true, '✅ Transaction deleted.', 'Transaction deleted via agent confirm.'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function createBudget(User $user, array $payload): array
    {
        $budget = $this->tools->applyCreateBudget($user, $payload);
        if (!$budget) {
            return [false, 'Those tags are no longer valid; budget was not created.', 'Budget tags no longer valid.'];
        }

        $label = $budget->title ?: ('Budget #' . $budget->id);
        $noun = $budget->isGoal() ? 'Savings goal' : 'Budget';

        return [true, "✅ {$noun} created: " . $label, "{$noun} created via agent confirm."];
    }

    /**
     * Budget edits / recurring rules (shared with Telegram).
     *
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: string, 2: string}
     */
    private function planning(User $user, string $kind, array $payload): array
    {
        $result = $this->tools->planning->apply($user, $kind, $payload);

        return [(bool) $result['ok'], (string) $result['message'], (string) $result['summary']];
    }

    private function txLine(User $user, string $prefix, \App\Models\Transaction $tx): string
    {
        $tx->loadMissing('tags');
        $row = $this->tools->serializeTransaction($user, $tx);
        $tags = implode(', ', array_map(
            static fn (array $t): string => trim($t['emoji'] . ' ' . $t['title']),
            $row['tags'],
        ));

        return trim(sprintf(
            '%s: %s · %s%s%s',
            $prefix,
            UserFormatter::formatMoney($user, (float) $tx->amount),
            (string) $row['at'],
            $tags !== '' ? ' · ' . $tags : '',
            $row['note'] !== '' ? ' · ' . $row['note'] : '',
        ));
    }

    private function markCard(WebAgentSession $session, string $pendingId, string $status): void
    {
        $session->patchItems(
            static fn (array $item): bool => ($item['kind'] ?? null) === 'confirm'
                && ($item['pending_id'] ?? null) === $pendingId
                && ($item['status'] ?? null) === 'pending',
            ['status' => $status],
        );
    }

    /**
     * @return array{ok: bool, status: 'applied'|'cancelled'|'failed', message: string, item: array<string, mixed>, refresh: bool}
     */
    private function result(WebAgentSession $session, bool $ok, string $status, string $message, bool $refresh): array
    {
        $session->addItem([
            'role' => 'assistant',
            'kind' => 'notice',
            'tone' => $ok ? ($status === 'cancelled' ? 'muted' : 'success') : 'error',
            'text' => $message,
        ] + ($ok && $status === 'applied' ? ['applied' => true] : []));
        $items = $session->state()['transcript'];

        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'item' => $items[count($items) - 1] ?? null,
            'refresh' => $refresh,
        ];
    }
}
