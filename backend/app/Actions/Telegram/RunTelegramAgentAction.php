<?php

namespace App\Actions\Telegram;

use App\Actions\Agent\AgentHistory;
use App\Actions\Agent\RunAgentAction;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Models\User;
use App\Support\TelegramAiQuota;

/**
 * Grok tool-calling agent for free-form Telegram finance questions.
 * Thin Telegram adapter over the transport-agnostic RunAgentAction loop.
 * Consumes 1 shared AI quota unit per user message that starts the agent (not per tool round).
 */
class RunTelegramAgentAction
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramChatSession $session,
        private readonly TelegramAiQuota $quota,
        private readonly TelegramAgentToolExecutor $tools,
        private readonly RunAgentAction $agent,
        private readonly AgentHistory $history,
    ) {}

    /**
     * @param array{prior_question?: string}|null $askContext When continuing after ask_user.
     * @param int|null $replyTo The user's message id; the answer replies to it.
     * @param string|null $quoted Text of the bot message the user replied to (context for the AI).
     * @return array{ok: bool, status: 'answered'|'ui_sent'|'ask_user'|'confirm_staged'|'unavailable'|'error', summary?: string}
     */
    public function execute(
        User $user,
        int|string $chatId,
        int|string $identity,
        string $text,
        ?array $askContext = null,
        ?int $replyTo = null,
        ?string $quoted = null,
    ): array {
        // Memory shared with the web assistant: private chats only (a group chat is not the user's own conversation).
        $shared = self::isPrivate($chatId, $identity);
        $prior = $shared ? $this->history->messages($user, AgentHistory::TELEGRAM, dropTrailingAsk: $askContext !== null) : [];
        if ($shared) {
            $this->history->add($user, AgentHistory::TELEGRAM, 'user', $text);
        }

        $quoted = $quoted !== null ? trim(preg_replace('/\s+/u', ' ', $quoted) ?? '') : '';
        if ($quoted !== '') {
            $cut = mb_strlen($quoted) > 500 ? mb_substr($quoted, 0, 500) . '…' : $quoted;
            $text = "(Replying to your earlier message: \"{$cut}\")\n" . $text;
        }

        $channel = $this->channel($chatId, $identity, $shared ? $user : null, $prior, $replyTo);
        $channel->typing(force: true);

        return $this->agent->execute($user, $channel, $text, $askContext);
    }

    public function quotaExhaustedMessage(User $user, int|string $chatId, int|string $identity): string
    {
        return $this->channel($chatId, $identity)->quotaExhaustedMessage($user);
    }

    public static function isPrivate(int|string $chatId, int|string $identity): bool
    {
        return (string) $chatId === (string) $identity;
    }

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $prior
     */
    private function channel(
        int|string $chatId,
        int|string $identity,
        ?User $sharedFor = null,
        array $prior = [],
        ?int $replyTo = null,
    ): TelegramAgentChannel {
        return new TelegramAgentChannel(
            $this->quota,
            $this->client,
            $this->session,
            $this->tools,
            $chatId,
            $identity,
            $sharedFor !== null ? $this->history : null,
            $sharedFor?->id,
            $prior,
            $replyTo,
        );
    }

    public function quota(): TelegramAiQuota
    {
        return $this->quota;
    }
}
