<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Agent\AgentHistory;
use App\Actions\Agent\AgentToolExecutor;
use App\Actions\Telegram\RunTelegramAgentAction;
use App\Actions\Telegram\TelegramAgentSession;
use App\Actions\Telegram\TelegramAgentToolExecutor;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Throwable;

class NaturalLanguageHandler
{
    private const OUTDATED_PREVIEW = 'This preview is outdated or was already handled. Ask again to get a fresh one.';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly RunTelegramAgentAction $agent,
        private readonly TelegramAgentToolExecutor $toolExecutor,
        private readonly TelegramAiQuota $quota,
        private readonly AgentToolExecutor $agentTools,
        private readonly TelegramChatSession $session,
        private readonly AgentHistory $history,
    ) {}

    /**
     * Try AI agent handling. Returns true when the message was consumed
     * (including unavailable / quota exhausted responses).
     *
     * @param int|null $replyTo The user's message id (the answer replies to it).
     * @param string|null $quoted The bot message the user replied to, if any.
     */
    public function tryHandle(
        User $user,
        int|string $chatId,
        string $text,
        int|string|null $telegramUserId = null,
        ?int $replyTo = null,
        ?string $quoted = null,
    ): bool {
        $identity = $telegramUserId ?? $chatId;
        $this->session->touch($identity, $chatId);

        if (!$this->looksLikeNaturalLanguage($text)) {
            return false;
        }

        if ((string) config('services.xai.api_key') === '') {
            return false;
        }

        if (!$this->quota->hasQuota($user)) {
            $this->support->sendQuotaExhausted($chatId, $user, $this->quota);
            $this->session->setSummary($identity, $chatId, 'AI quota exhausted.');

            return true;
        }

        // The agent shows "typing…" while it works (no separate "Thinking…" message).
        $result = $this->agent->execute($user, $chatId, $identity, $text, null, $replyTo, $quoted);

        if (!$result['ok'] && ($result['status'] ?? '') === 'unavailable') {
            $this->client->sendMessage(
                $chatId,
                'AI is temporarily unavailable. Tap ➕ Add (or send /add) to log a transaction, or use the menu buttons',
            );
            $this->session->setSummary($identity, $chatId, 'AI temporarily unavailable.');

            return true;
        }

        if (!$result['ok']) {
            $this->client->sendMessage(
                $chatId,
                "Didn't catch that. Try 350 groceries, show recent, my budgets, or /help",
            );
            $this->session->setSummary($identity, $chatId, "Didn't catch an action.");

            return true;
        }

        $this->maybeHintLowRemaining($user, $chatId);

        return true;
    }

    /**
     * Continue the agent after an ask_user reply (free text or suggestion tap).
     */
    public function handlePendingAskAnswer(
        User $user,
        int|string $chatId,
        string $text,
        int|string|null $telegramUserId = null,
        ?int $replyTo = null,
        ?string $quoted = null,
    ): bool {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'agent_ask') {
            return false;
        }

        $question = (string) ($pending['question'] ?? '');
        $this->session->clearPending($identity, $chatId);

        if ((string) config('services.xai.api_key') === '') {
            $this->client->sendMessage($chatId, 'AI is temporarily unavailable. Try /help or menu buttons.');

            return true;
        }

        if (!$this->quota->hasQuota($user)) {
            $this->support->sendQuotaExhausted($chatId, $user, $this->quota);

            return true;
        }

        $result = $this->agent->execute($user, $chatId, $identity, $text, [
            'prior_question' => $question,
        ], $replyTo, $quoted);

        if (!$result['ok'] && ($result['status'] ?? '') === 'unavailable') {
            $this->client->sendMessage(
                $chatId,
                'AI is temporarily unavailable. Tap ➕ Add (or send /add) to log a transaction, or use the menu buttons',
            );

            return true;
        }

        $this->maybeHintLowRemaining($user, $chatId);

        return true;
    }

    public function handleAskChoice(
        User $user,
        int|string $chatId,
        int $choiceIndex,
        string $callbackId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'agent_ask') {
            $this->client->answerCallbackQuery($callbackId, 'That question expired.');

            return;
        }

        $suggestions = is_array($pending['suggestions'] ?? null) ? $pending['suggestions'] : [];
        if (!isset($suggestions[$choiceIndex]) || !is_string($suggestions[$choiceIndex])) {
            $this->client->answerCallbackQuery($callbackId, 'Invalid choice.');

            return;
        }

        $answer = $suggestions[$choiceIndex];
        $this->client->answerCallbackQuery($callbackId, $answer);
        $this->handlePendingAskAnswer($user, $chatId, $answer, $telegramUserId);
    }

    private function maybeHintLowRemaining(User $user, int|string $chatId): void
    {
        $hint = $this->quota->lowRemainingHint($user);
        if ($hint !== null) {
            $this->client->sendMessage($chatId, $hint);
        }
    }

    public function confirmCreateTags(
        User $user,
        int|string $chatId,
        string $nonce,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->takePending($identity, $chatId, 'create_tags', $nonce);

        if ($pending === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_PREVIEW);

            return;
        }

        $titles = is_array($pending['titles'] ?? null)
            ? array_values(array_filter($pending['titles'], 'is_string'))
            : [];
        $this->createTags($user, $chatId, $identity, $this->agentTools->filterNewTagTitles($user, $titles));
        $this->markProposal($user, $chatId, $identity, $nonce, 'confirmed');
    }

    public function cancelCreateTags(
        int|string $chatId,
        string $nonce,
        int|string|null $telegramUserId = null,
        ?User $user = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;

        if ($this->takePending($identity, $chatId, 'create_tags', $nonce) === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_PREVIEW);

            return;
        }

        $this->session->setSummary($identity, $chatId, 'Tag creation cancelled.');
        $this->client->sendMessage($chatId, '↩️ Cancelled');
        $this->markProposal($user, $chatId, $identity, $nonce, 'cancelled');
    }

    public function confirmRenameTags(
        User $user,
        int|string $chatId,
        string $nonce,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->takePending($identity, $chatId, 'rename_tags', $nonce);

        if ($pending === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_PREVIEW);

            return;
        }

        $proposals = $this->agentTools->normalizeRenameProposals($pending['proposals'] ?? null);

        if ($proposals === []) {
            $this->client->sendMessage($chatId, 'The rename preview is stale or has no valid changes.');

            return;
        }

        try {
            $this->agentTools->renameTags($user, $proposals);
        } catch (Throwable) {
            $this->client->sendMessage($chatId, 'Could not apply the rename preview; no changes were saved.');
            $this->session->setSummary($identity, $chatId, 'Rename failed because a tag changed or could not be updated.');
            $this->markProposal($user, $chatId, $identity, $nonce, 'failed');

            return;
        }

        $this->agentTools->saveTagListSession($user, new TelegramAgentSession($this->session, $identity, $chatId));
        $preview = implode("\n", array_map(
            static fn (array $proposal): string => $proposal['before'] . ' → ' . $proposal['after'],
            $proposals,
        ));
        $this->session->setSummary($identity, $chatId, 'Tags renamed.');
        $this->client->sendMessage($chatId, "✅ Renamed tags:\n" . $preview);
        $this->markProposal($user, $chatId, $identity, $nonce, 'confirmed');
    }

    public function cancelRenameTags(
        int|string $chatId,
        string $nonce,
        int|string|null $telegramUserId = null,
        ?User $user = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;

        if ($this->takePending($identity, $chatId, 'rename_tags', $nonce) === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_PREVIEW);

            return;
        }

        $this->session->setSummary($identity, $chatId, 'Tag rename cancelled.');
        $this->client->sendMessage($chatId, '↩️ Cancelled');
        $this->markProposal($user, $chatId, $identity, $nonce, 'cancelled');
    }

    public function confirmAgentMutation(
        User $user,
        int|string $chatId,
        string $nonce,
        int|string|null $telegramUserId = null,
        ?int $messageId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->takePending($identity, $chatId, 'agent_mutation', $nonce);

        if ($pending === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_PREVIEW);

            return;
        }

        // A transaction confirm card turns into the result card in place.
        $inPlace = in_array($pending['action'] ?? '', TelegramAgentToolExecutor::TRANSACTION_ACTIONS, true) ? $messageId : null;
        $applied = $this->toolExecutor->applyAgentMutation($user, $chatId, $identity, $pending, $inPlace);
        $this->markProposal($user, $chatId, $identity, $nonce, $applied ? 'confirmed' : 'failed');
    }

    /**
     * Update the proposal's status in the memory shared with the web assistant (private chats only).
     */
    private function markProposal(?User $user, int|string $chatId, int|string $identity, string $nonce, string $status): void
    {
        if (!RunTelegramAgentAction::isPrivate($chatId, $identity)) {
            return;
        }
        $user ??= $this->support->resolveUser($chatId);
        if ($user !== null) {
            $this->history->setStatus($user, $nonce, $status, AgentHistory::TELEGRAM);
        }
    }

    public function cancelAgentMutation(
        int|string $chatId,
        string $nonce,
        int|string|null $telegramUserId = null,
        ?User $user = null,
        ?int $messageId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->takePending($identity, $chatId, 'agent_mutation', $nonce);

        if ($pending === null) {
            $this->client->sendMessage($chatId, self::OUTDATED_PREVIEW);

            return;
        }

        $this->session->setSummary($identity, $chatId, 'Agent mutation cancelled.');
        $action = (string) ($pending['action'] ?? '');
        $transaction = $user && in_array($action, ['update_transaction', 'delete_transaction'], true)
            ? $this->support->findOwnTransaction($user, (int) ($pending['payload']['id'] ?? 0))
            : null;

        if ($messageId !== null && $transaction) {
            // The card stays, showing the transaction as it still is.
            $this->client->editMessageText(
                $chatId,
                $messageId,
                $this->support->formatTransactionCard($user, $transaction, '↩️ Cancelled, nothing changed'),
                null,
                TelegramSupport::PARSE_HTML,
            );
        } elseif ($messageId !== null && $action === 'create_transaction') {
            $this->client->editMessageText($chatId, $messageId, '↩️ Cancelled, nothing saved');
        } else {
            $this->client->sendMessage($chatId, '↩️ Cancelled');
        }
        $this->markProposal($user, $chatId, $identity, $nonce, 'cancelled');
    }

    /**
     * The pending change behind a confirm/cancel button, removed from the
     * session. Null when the button belongs to an older preview, the type
     * doesn't match, or the button was already used.
     *
     * @return array<string, mixed>|null
     */
    private function takePending(int|string $identity, int|string $chatId, string $type, string $nonce): ?array
    {
        $pending = $this->session->get($identity, $chatId)['pending'];

        if (!is_array($pending) || ($pending['type'] ?? null) !== $type || ($pending['nonce'] ?? null) !== $nonce) {
            return null;
        }

        if (!$this->support->claimNonce($nonce)) {
            return null;
        }

        $this->session->clearPending($identity, $chatId);

        return $pending;
    }

    /**
     * Every non-command text goes to the agent (there is no regex quick-add any more).
     */
    private function looksLikeNaturalLanguage(string $text): bool
    {
        $trimmed = trim($text);

        // Every non-command text goes to the agent, a bare "350" too (it logs an expense).
        return $trimmed !== '' && !str_starts_with($trimmed, '/');
    }

    /**
     * @param list<string> $titles
     */
    private function createTags(User $user, int|string $chatId, int|string $identity, array $titles): void
    {
        if ($titles === []) {
            $this->client->sendMessage($chatId, 'Those tags already exist (or none were left to create).');

            return;
        }

        $created = $this->agentTools->createTags($user, $titles);

        $this->agentTools->saveTagListSession($user, new TelegramAgentSession($this->session, $identity, $chatId));
        $summary = '✅ Created tags: ' . implode(', ', $created);
        $this->session->setSummary($identity, $chatId, $summary);
        $this->client->sendMessage($chatId, $summary);
    }
}
