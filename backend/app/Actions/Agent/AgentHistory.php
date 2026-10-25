<?php

namespace App\Actions\Agent;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Conversation memory shared by the web and Telegram assistants: the last few turns of
 * both channels, per user, in the cache (sliding TTL, config('agent.shared_history_*')).
 *
 * Only text goes here. Pending confirms stay in each channel's own session; a proposal is
 * recorded as text with a status (pending → confirmed / cancelled / failed / outdated), so the
 * other channel knows about it but can't act on it. Telegram writes only from private chats.
 *
 * Item: {id, role: user|assistant, channel: web|telegram, kind: text|ask|proposal, text, at,
 * turn_id?, ref?, status?}.
 */
final class AgentHistory
{
    public const WEB = 'web';

    public const TELEGRAM = 'telegram';

    public const MAX_TEXT = 1500;

    /**
     * @param array{turn_id?: string|null, ref?: string|null, status?: string|null, kind?: string} $meta
     */
    public function add(User|int $user, string $channel, string $role, string $text, array $meta = []): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $item = [
            'id' => (string) Str::uuid(),
            'role' => $role === 'user' ? 'user' : 'assistant',
            'channel' => $channel === self::TELEGRAM ? self::TELEGRAM : self::WEB,
            'kind' => in_array($meta['kind'] ?? 'text', ['text', 'ask', 'proposal'], true) ? ($meta['kind'] ?? 'text') : 'text',
            'text' => mb_substr($text, 0, self::MAX_TEXT),
            'at' => now()->toIso8601String(),
        ];
        foreach (['turn_id', 'ref', 'status'] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                $item[$key] = $meta[$key];
            }
        }

        $this->update($user, function (array $items) use ($item): array {
            $items[] = $item;
            $max = max(1, (int) config('agent.shared_history_max'));

            return count($items) > $max ? array_slice($items, -$max) : $items;
        });
    }

    /**
     * A staged change, as text: "Proposed change (pending via Telegram): …". A newer proposal in
     * the same channel replaces the old one there, so older pending ones turn "outdated".
     */
    public function addProposal(User|int $user, string $channel, string $preview, string $ref, ?string $turnId = null): void
    {
        $this->setStatus($user, null, 'outdated', $channel);
        $this->add($user, $channel, 'assistant', $preview, ['kind' => 'proposal', 'ref' => $ref, 'status' => 'pending', 'turn_id' => $turnId]);
    }

    /**
     * Resolve a pending proposal (by its ref, or every pending one of $channel when $ref is null).
     */
    public function setStatus(User|int $user, ?string $ref, string $status, ?string $channel = null): void
    {
        $this->update($user, static function (array $items) use ($ref, $status, $channel): array {
            foreach ($items as $i => $item) {
                $match = ($item['kind'] ?? null) === 'proposal'
                    && ($item['status'] ?? null) === 'pending'
                    && ($ref === null || ($item['ref'] ?? null) === $ref)
                    && ($channel === null || ($item['channel'] ?? null) === $channel);
                if ($match) {
                    $items[$i]['status'] = $status;
                }
            }

            return $items;
        }, createIfMissing: false);
    }

    /**
     * Drop a rewound (edited / regenerated) turn of one channel.
     */
    public function dropTurn(User|int $user, string $channel, string $turnId): void
    {
        $this->update($user, static fn (array $items): array => array_values(array_filter(
            $items,
            static fn (array $item): bool => !(($item['channel'] ?? null) === $channel && ($item['turn_id'] ?? null) === $turnId),
        )), createIfMissing: false);
    }

    public function clear(User|int $user): void
    {
        Cache::forget($this->key($user));
    }

    /**
     * Prior turns for the model, oldest first, each tagged with its channel.
     * $dropTrailingAsk removes the last item when it is this channel's pending question
     * (the agent loop re-adds that question itself as the ask context).
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function messages(User|int $user, string $channel, bool $dropTrailingAsk = false, ?int $limit = null): array
    {
        $items = $this->items($user);

        if ($dropTrailingAsk && $items !== []) {
            $last = $items[count($items) - 1];
            if (($last['kind'] ?? null) === 'ask' && ($last['channel'] ?? null) === $channel) {
                array_pop($items);
            }
        }

        $limit ??= max(0, (int) config('agent.web_history_turns'));
        if ($limit === 0) {
            return [];
        }

        $out = [];
        foreach (array_slice($items, -$limit) as $item) {
            $via = ($item['channel'] ?? null) === self::TELEGRAM ? 'Telegram' : 'web';
            $text = (string) ($item['text'] ?? '');
            $content = ($item['kind'] ?? null) === 'proposal'
                ? 'Proposed change (' . ($item['status'] ?? 'pending') . " via {$via}): " . $text
                : "[via {$via}] " . $text;
            $out[] = [
                'role' => ($item['role'] ?? 'assistant') === 'user' ? 'user' : 'assistant',
                'content' => $content,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(User|int $user): array
    {
        $data = Cache::get($this->key($user));

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $fn
     */
    private function update(User|int $user, callable $fn, bool $createIfMissing = true): void
    {
        $key = $this->key($user);
        $write = function () use ($key, $fn, $user, $createIfMissing): void {
            if (!$createIfMissing && !Cache::has($key)) {
                return;
            }
            $ttl = now()->addMinutes(max(1, (int) config('agent.shared_history_ttl_minutes')));
            Cache::put($key, array_values($fn($this->items($user))), $ttl);
        };

        // Web and Telegram may write at the same moment; a short lock keeps both items.
        try {
            Cache::lock($key . ':lock', 5)->block(2, $write);
        } catch (LockTimeoutException) {
            $write();
        }
    }

    private function key(User|int $user): string
    {
        return 'agent_history:' . ($user instanceof User ? $user->id : $user);
    }
}
