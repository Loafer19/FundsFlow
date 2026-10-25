<?php

namespace App\Actions\Agent;

/**
 * Short-lived conversational state the agent tools read and write
 * (domain, last_list, one pending slot, last bot summary). Each transport
 * (Telegram chat, web chat drawer) provides its own storage.
 */
interface AgentSession
{
    /**
     * @return array{
     *     domain: 'tags'|'budgets'|'transactions'|null,
     *     last_list: list<array{id: int, title: string, emoji?: string}>,
     *     pending: array<string, mixed>|null,
     *     last_bot_summary: string
     * }
     */
    public function get(): array;

    public function setDomain(?string $domain): void;

    /**
     * @param list<array{id: int, title: string, emoji?: string}> $items
     */
    public function setLastList(array $items): void;

    /**
     * @param array<string, mixed>|null $pending
     */
    public function setPending(?array $pending): void;

    public function setSummary(string $summary): void;
}
