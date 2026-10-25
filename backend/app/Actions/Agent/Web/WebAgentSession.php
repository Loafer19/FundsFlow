<?php

namespace App\Actions\Agent\Web;

use App\Actions\Agent\AgentSession;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache-backed web chat conversation for one user: agent context (domain,
 * last_list, one pending slot, summary) plus the visible transcript.
 * Sliding TTL: config('agent.web_session_ttl_minutes').
 */
final class WebAgentSession implements AgentSession
{
    public function __construct(
        private readonly User $user,
    ) {}

    /**
     * Full stored state.
     *
     * @return array{
     *     conversation_id: string,
     *     domain: 'tags'|'budgets'|'transactions'|null,
     *     last_list: list<array{id: int, title: string, emoji?: string}>,
     *     pending: array<string, mixed>|null,
     *     last_bot_summary: string,
     *     transcript: list<array<string, mixed>>,
     *     expires_at: string|null
     * }
     */
    public function state(): array
    {
        $data = Cache::get($this->key());

        return $this->normalize(is_array($data) ? $data : []);
    }

    public function get(): array
    {
        $state = $this->state();

        return [
            'domain' => $state['domain'],
            'last_list' => $state['last_list'],
            'pending' => $state['pending'],
            'last_bot_summary' => $state['last_bot_summary'],
        ];
    }

    public function conversationId(): string
    {
        $state = $this->state();
        if (Cache::has($this->key())) {
            return $state['conversation_id'];
        }

        // Persist a fresh id so the client can keep referring to it.
        return $this->put($state)['conversation_id'];
    }

    public function setDomain(?string $domain): void
    {
        $this->update(fn (array $s): array => ['domain' => $domain] + $s);
    }

    public function setLastList(array $items): void
    {
        $this->update(fn (array $s): array => ['last_list' => $items] + $s);
    }

    public function setPending(?array $pending): void
    {
        $this->update(fn (array $s): array => ['pending' => $pending] + $s);
    }

    public function setSummary(string $summary): void
    {
        $this->update(fn (array $s): array => ['last_bot_summary' => mb_substr(trim($summary), 0, 2000)] + $s);
    }

    /**
     * Append a chat item (role user|assistant, kind text|confirm|ask|chart|show|notice).
     *
     * @param array<string, mixed> $item
     */
    public function addItem(array $item): void
    {
        $item['id'] ??= (string) Str::uuid();
        $item['at'] ??= now()->toIso8601String();

        $this->update(function (array $s) use ($item): array {
            $s['transcript'][] = $item;
            $max = max(1, (int) config('agent.web_transcript_max'));
            if (count($s['transcript']) > $max) {
                $s['transcript'] = array_slice($s['transcript'], -$max);
            }

            return $s;
        });
    }

    /**
     * Patch an existing transcript item (e.g. mark a confirm card resolved).
     *
     * @param callable(array<string, mixed>): bool $match
     * @param array<string, mixed> $patch
     */
    public function patchItems(callable $match, array $patch): void
    {
        $this->update(function (array $s) use ($match, $patch): array {
            foreach ($s['transcript'] as $i => $item) {
                if ($match($item)) {
                    $s['transcript'][$i] = array_replace($item, $patch);
                }
            }

            return $s;
        });
    }

    /**
     * Prior text turns for the model (oldest first), excluding the trailing pending ask question.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function historyMessages(bool $dropTrailingAsk = false): array
    {
        $items = $this->state()['transcript'];

        if ($dropTrailingAsk && $items !== [] && ($items[count($items) - 1]['kind'] ?? null) === 'ask') {
            array_pop($items);
        }

        $out = [];
        foreach ($items as $item) {
            $text = match ($item['kind'] ?? 'text') {
                'text', 'notice' => (string) ($item['text'] ?? ''),
                'ask' => (string) ($item['question'] ?? ''),
                'confirm' => 'Proposed change (' . ($item['status'] ?? 'pending') . '): ' . (string) ($item['preview'] ?? ''),
                'chart' => 'Chart shown: ' . (string) ($item['caption'] ?? ''),
                'show' => 'Card shown: ' . (string) ($item['title'] ?? ''),
                default => '',
            };
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'role' => ($item['role'] ?? 'assistant') === 'user' ? 'user' : 'assistant',
                'content' => mb_substr($text, 0, 1500),
            ];
        }

        $turns = max(0, (int) config('agent.web_history_turns'));

        return $turns === 0 ? [] : array_slice($out, -$turns);
    }

    public function clear(): void
    {
        Cache::forget($this->key());
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $fn
     */
    private function update(callable $fn): void
    {
        $this->put($fn($this->state()));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function put(array $data): array
    {
        $ttl = now()->addMinutes(max(1, (int) config('agent.web_session_ttl_minutes')));
        $state = $this->normalize($data);
        $state['expires_at'] = $ttl->toIso8601String();
        Cache::put($this->key(), $state, $ttl);

        return $state;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $domain = $data['domain'] ?? null;
        $lastList = [];
        foreach (is_array($data['last_list'] ?? null) ? $data['last_list'] : [] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['title'])) {
                continue;
            }
            $id = filter_var($item['id'], FILTER_VALIDATE_INT);
            $title = trim((string) $item['title']);
            if ($id === false || $id < 1 || $title === '') {
                continue;
            }
            $row = ['id' => (int) $id, 'title' => mb_substr($title, 0, 255)];
            if (isset($item['emoji']) && is_string($item['emoji']) && trim($item['emoji']) !== '') {
                $row['emoji'] = mb_substr(trim($item['emoji']), 0, 255);
            }
            $lastList[] = $row;
        }

        $conversationId = is_string($data['conversation_id'] ?? null) && $data['conversation_id'] !== ''
            ? $data['conversation_id']
            : (string) Str::uuid();

        return [
            'conversation_id' => $conversationId,
            'domain' => in_array($domain, ['tags', 'budgets', 'transactions'], true) ? $domain : null,
            'last_list' => $lastList,
            'pending' => is_array($data['pending'] ?? null) ? $data['pending'] : null,
            'last_bot_summary' => is_string($data['last_bot_summary'] ?? null) ? $data['last_bot_summary'] : '',
            'transcript' => array_values(array_filter(
                is_array($data['transcript'] ?? null) ? $data['transcript'] : [],
                'is_array',
            )),
            'expires_at' => is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null,
        ];
    }

    private function key(): string
    {
        return 'web_agent_session:' . $this->user->id;
    }
}
