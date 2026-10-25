<?php

namespace App\Actions\Telegram;

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
    ) {}

    /**
     * @param array{prior_question?: string}|null $askContext When continuing after ask_user.
     * @return array{ok: bool, status: 'answered'|'ui_sent'|'ask_user'|'confirm_staged'|'unavailable'|'error', summary?: string}
     */
    public function execute(
        User $user,
        int|string $chatId,
        int|string $identity,
        string $text,
        ?array $askContext = null,
    ): array {
        return $this->agent->execute($user, $this->channel($chatId, $identity), $text, $askContext);
    }

    public function quotaExhaustedMessage(User $user, int|string $chatId, int|string $identity): string
    {
        return $this->channel($chatId, $identity)->quotaExhaustedMessage($user);
    }

    private function channel(int|string $chatId, int|string $identity): TelegramAgentChannel
    {
        return new TelegramAgentChannel($this->quota, $this->client, $this->session, $this->tools, $chatId, $identity);
    }

    public function quota(): TelegramAiQuota
    {
        return $this->quota;
    }
}
