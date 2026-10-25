<?php

namespace App\Actions\Agent\Web;

use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Agent\RunAgentAction;
use App\Actions\Charts\BuildUserChartAction;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Closure;

/**
 * One web chat turn: quota gate (shared with Telegram), ask_user continuation,
 * agent loop with streaming, then quota + done events.
 *
 * Events emitted: meta, delta, tool, answer, notice, show, chart, ask, confirm, quota, error, done.
 */
final class RunWebAgentAction
{
    public function __construct(
        private readonly RunAgentAction $agent,
        private readonly AgentToolExecutor $tools,
        private readonly BuildUserChartAction $buildChart,
        private readonly TelegramAiQuota $quota,
    ) {}

    /**
     * @param Closure(string, array<string, mixed>): void $emit
     */
    public function execute(User $user, string $message, ?string $conversationId, Closure $emit): void
    {
        $session = new WebAgentSession($user);
        $currentId = $session->conversationId();
        $emit('meta', [
            'conversation_id' => $currentId,
            'stale' => $conversationId !== null && $conversationId !== '' && $conversationId !== $currentId,
        ]);

        $message = trim($message);
        $pending = $session->get()['pending'];
        $askContext = null;
        if (is_array($pending) && ($pending['type'] ?? null) === 'agent_ask') {
            $askContext = ['prior_question' => (string) ($pending['prior_question'] ?? '')];
            $session->setPending(null);
            $session->patchItems(
                static fn (array $item): bool => ($item['kind'] ?? null) === 'ask' && ($item['status'] ?? null) === 'pending',
                ['status' => 'answered'],
            );
        }

        $history = $session->historyMessages(dropTrailingAsk: $askContext !== null);

        $channel = new WebAgentChannel($this->quota, $this->tools, $this->buildChart, $session, $emit, $history);
        $channel->push('user', ['role' => 'user', 'kind' => 'text', 'text' => $message]);

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
     * @return array<string, mixed>
     */
    public function quotaPayload(User $user): array
    {
        return $this->quota->snapshot($user) + ['hint' => $this->quota->lowRemainingHint($user)];
    }
}
