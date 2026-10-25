<?php

namespace App\Actions\Telegram;

use App\Actions\Agent\AgentSession;
use App\Channels\Telegram\TelegramChatSession;

/**
 * AgentSession view of one Telegram chat (identity + chat id) on TelegramChatSession.
 */
final class TelegramAgentSession implements AgentSession
{
    public function __construct(
        private readonly TelegramChatSession $session,
        private readonly int|string $identity,
        private readonly int|string $chatId,
    ) {}

    public function get(): array
    {
        return $this->session->get($this->identity, $this->chatId);
    }

    public function setDomain(?string $domain): void
    {
        $this->session->setDomain($this->identity, $this->chatId, $domain);
    }

    public function setLastList(array $items): void
    {
        $this->session->setLastList($this->identity, $this->chatId, $items);
    }

    public function setPending(?array $pending): void
    {
        $this->session->setPending($this->identity, $this->chatId, $pending);
    }

    public function setSummary(string $summary): void
    {
        $this->session->setSummary($this->identity, $this->chatId, $summary);
    }
}
