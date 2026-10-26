<?php

namespace App\Actions\Agent\Web;

use App\Actions\Agent\AgentHistory;
use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Agent\RunAgentAction;
use App\Actions\Charts\BuildUserChartAction;
use App\Actions\Report\BuildUserReportAction;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Closure;

/**
 * One web chat turn: quota gate (shared with Telegram), ask_user continuation,
 * agent loop with streaming, then quota + done events.
 *
 * Messages with receipt photos (and replies to a receipt question) go to RunWebReceiptAction.
 *
 * Events emitted: meta, user, delta, tool, answer, notice, show, chart, ask, confirm, quota, error, done.
 */
final class RunWebAgentAction
{
    public function __construct(
        private readonly RunAgentAction $agent,
        private readonly AgentToolExecutor $tools,
        private readonly BuildUserChartAction $buildChart,
        private readonly TelegramAiQuota $quota,
        private readonly BuildUserReportAction $buildReport,
        private readonly RunWebReceiptAction $receipt,
        private readonly WebAgentUploads $uploads,
    ) {}

    /**
     * @param Closure(string, array<string, mixed>): void $emit
     * @param array<string, mixed> $context validated screen context (tab, period, tag filter)
     * @param list<array{id: string, name: string, mime: string, file_size: int}> $files receipt photos, or one document (WebAgentUploads refs)
     * @param array{prior_question: string}|null $rewoundAsk the question an edited / regenerated turn answered
     *        (WebAgentSession::rewindLastTurn already dropped that turn)
     */
    public function execute(
        User $user,
        string $message,
        ?string $conversationId,
        Closure $emit,
        array $context = [],
        array $files = [],
        ?array $rewoundAsk = null,
    ): void {
        $session = new WebAgentSession($user);
        $currentId = $session->conversationId();
        $emit('meta', [
            'conversation_id' => $currentId,
            'stale' => $conversationId !== null && $conversationId !== '' && $conversationId !== $currentId,
        ]);

        $message = trim($message);
        $pending = $session->get()['pending'];
        $askContext = $rewoundAsk;

        // New photos start a fresh receipt flow: whatever card was waiting (a question or a
        // Confirm, like stage() does for a new preview) is superseded and its photos dropped.
        if ($files !== [] && is_array($pending)) {
            $this->uploads->delete($user, WebAgentUploads::refsIn($pending));
            $session->setPending(null);
            $session->patchItems(
                static fn (array $item): bool => ($item['kind'] ?? null) === 'ask' && ($item['status'] ?? null) === 'pending',
                ['status' => 'answered'],
            );
            $session->patchItems(
                static fn (array $item): bool => ($item['kind'] ?? null) === 'confirm' && ($item['status'] ?? null) === 'pending',
                ['status' => 'outdated'],
            );
            $pending = null;
        }

        if (is_array($pending) && ($pending['type'] ?? null) === 'agent_ask') {
            $askContext = ['prior_question' => (string) ($pending['prior_question'] ?? '')];
            $session->setPending(null);
            $session->patchItems(
                static fn (array $item): bool => ($item['kind'] ?? null) === 'ask' && ($item['status'] ?? null) === 'pending',
                ['status' => 'answered'],
            );
        }

        // Model context: the conversation memory shared with Telegram (web turns + private-chat Telegram turns).
        $history = app(AgentHistory::class)->messages($user, AgentHistory::WEB, dropTrailingAsk: $askContext !== null);

        $channel = new WebAgentChannel($this->quota, $this->tools, $this->buildChart, $session, $emit, $history, $context, $this->buildReport);
        // reply_to lets an edit / regenerate of this turn restore its question (agent_ask) or refuse
        // (receipt, set below once the receipt flow took the answer).
        $replyTo = $files === [] && $askContext !== null
            ? ['reply_to' => 'agent_ask', 'prior_question' => $askContext['prior_question']]
            : [];
        $documents = array_values(array_filter($files, static fn (array $file): bool => WebAgentUploads::isDocumentRef($file)));
        $document = $documents[0] ?? null;
        $userItem = $channel->push('user', ['role' => 'user', 'kind' => 'text', 'text' => $message]
            + ($document !== null
                ? ['document' => ['name' => (string) $document['name'], 'size' => (int) $document['file_size']]]
                    + (count($documents) > 1 ? ['documents' => array_map(static fn (array $doc) => ['name' => (string) $doc['name'], 'size' => (int) $doc['file_size']], $documents)] : [])
                : ($files !== [] ? ['photos' => count($files)] : []))
            + $replyTo);

        // Documents: one receipt or ONE bulk import of all files (shared with Telegram, see DocumentImport).
        if ($document !== null) {
            $status = $this->receipt->startDocuments($user, $channel, $session, $documents, $message);
            $this->finish($user, $session, $emit, $status);

            return;
        }

        // Receipt photos: the shared receipt flow (quota is checked per analysis, like Telegram).
        if ($files !== []) {
            $status = $this->receipt->start($user, $channel, $session, $files, $message);
            $this->finish($user, $session, $emit, $status);

            return;
        }

        if (RunWebReceiptAction::isReceiptPending($pending)) {
            $status = $this->receipt->continue($user, $channel, $session, $pending, $message);
            if ($status !== null) {
                $session->patchItems(
                    static fn (array $item): bool => ($item['id'] ?? null) === ($userItem['id'] ?? false),
                    ['reply_to' => 'receipt'],
                );
                $this->finish($user, $session, $emit, $status);

                return;
            }
        }

        if ((string) config('services.xai.api_key') === '') {
            $this->fail($channel, $emit, 'unavailable', 'AI is not configured on this server.');
            $this->finish($user, $session, $emit, 'unavailable');

            return;
        }

        if (!$this->quota->hasQuota($user)) {
            $this->fail($channel, $emit, 'quota_exhausted', $channel->quotaExhaustedMessage($user));
            $session->setSummary('AI quota exhausted.');
            $this->finish($user, $session, $emit, 'quota_exhausted');

            return;
        }

        $result = $this->agent->execute($user, $channel, $message, $askContext);

        if (!$result['ok'] && ($result['status'] ?? '') === 'unavailable') {
            $this->fail($channel, $emit, 'unavailable', 'AI is temporarily unavailable. Try again in a moment.');
            $session->setSummary('AI temporarily unavailable.');
            $this->finish($user, $session, $emit, 'unavailable');

            return;
        }

        if (!$result['ok']) {
            $this->fail($channel, $emit, 'empty', "Didn't catch an action there. Try show recent, budgets, month summary, or a chart of spending by tag.");
            $session->setSummary("Didn't catch an action.");
            $this->finish($user, $session, $emit, 'error');

            return;
        }

        $this->finish($user, $session, $emit, (string) ($result['status'] ?? 'answered'));
    }

    /**
     * @param Closure(string, array<string, mixed>): void $emit
     */
    private function fail(WebAgentChannel $channel, Closure $emit, string $code, string $message): void
    {
        $item = $channel->push('notice', ['role' => 'assistant', 'kind' => 'notice', 'tone' => 'error', 'text' => $message]);
        $emit('error', ['code' => $code, 'message' => $message, 'item_id' => $item['id'] ?? null]);
    }

    /**
     * @param Closure(string, array<string, mixed>): void $emit
     */
    private function finish(User $user, WebAgentSession $session, Closure $emit, string $status): void
    {
        $emit('quota', $this->quotaPayload($user));
        $emit('done', ['status' => $status, 'conversation_id' => $session->conversationId()]);
    }

    /**
     * Quota snapshot for the drawer badge. `resets_at` is the next midnight in the user's timezone
     * (ISO 8601 with offset); `low_threshold` is when the badge turns into a warning.
     *
     * @return array<string, mixed>
     */
    public function quotaPayload(User $user): array
    {
        $snapshot = $this->quota->snapshot($user);

        return $snapshot + [
            'resets_at' => $snapshot['reset_at'],
            'low_threshold' => max(0, (int) config('telegram.low_quota_threshold')),
            'hint' => $this->quota->lowRemainingHint($user),
        ];
    }
}
