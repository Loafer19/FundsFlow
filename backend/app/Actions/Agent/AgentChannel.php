<?php

namespace App\Actions\Agent;

use App\Models\User;

/**
 * Transport adapter for RunAgentAction: supplies the prompt and tool catalog,
 * executes tools (UI tools render in the transport's own way) and receives the
 * loop's output.
 */
interface AgentChannel
{
    public function systemPrompt(User $user): string;

    /**
     * Prior conversation turns placed between the system prompt and the new user message.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function historyMessages(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array;

    /**
     * Tools that stage a Confirm/Cancel preview; only one may be staged per batch.
     *
     * @return list<string>
     */
    public function stagingTools(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array{ok: bool, control: ?string, result: array<string, mixed>}
     */
    public function executeTool(User $user, string $name, array $arguments): array;

    /**
     * Note appended to the tool result for the model when a tool returned a stop control.
     */
    public function controlNote(string $control): ?string;

    /**
     * Whether to request xAI streaming and forward text chunks via onDelta().
     */
    public function streams(): bool;

    public function onDelta(string $text): void;

    /**
     * Called right before a tool runs (status updates).
     *
     * @param array<string, mixed> $arguments
     */
    public function onToolCall(string $name, array $arguments): void;

    /**
     * Final assistant text (no tool calls in that round).
     */
    public function onAnswer(string $text): void;

    public function onMaxRounds(): void;

    /**
     * Shown instead of running the agent when the shared daily AI quota is used up:
     * when it resets plus what still works without AI in this channel.
     */
    public function quotaExhaustedMessage(User $user): string;
}
