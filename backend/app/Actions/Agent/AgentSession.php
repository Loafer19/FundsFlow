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
     *     domain: null|'budgets'|'tags'|'transactions',
     *     last_list: list<array{id: int, title: string, emoji?: string}>,
     *     pending: null|array<string, mixed>,
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
     * @param null|array<string, mixed> $pending
     */
    public function setPending(?array $pending): void;

    public function setSummary(string $summary): void;
}
