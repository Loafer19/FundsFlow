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
    public const REWIND_REPLACE = 'replace_last';

    public const REWIND_REGENERATE = 'regenerate';

    public function __construct(
        private readonly User $user,
    ) {}

    public function user(): User
    {
        return $this->user;
    }

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
     * A user text item may carry `photos` (count of receipt photos sent with it).
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
            $photos = (int) ($item['photos'] ?? 0);
            $text = match ($item['kind'] ?? 'text') {
                'text' => (string) ($item['text'] ?? '') . ($photos > 0 ? " [sent {$photos} receipt photo(s)]" : ''),
                'notice' => (string) ($item['text'] ?? ''),
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

    /**
     * Edit (replace_last) or regenerate the last turn: the last user message and everything after it
     * are dropped so the new run doesn't see the old exchange twice. Only that turn, and only while
     * nothing from it was saved:
     *
     * - never when a change from the turn was confirmed (a confirmed card or a success notice in it),
     *   or when the message answered a receipt question (the receipt flow already used it);
     * - regenerate also needs a plain text turn: no receipt photos and no Confirm card at all
     *   (a pending one would just be staged again; re-asking is the honest way).
     *
     * `$turnId` is the id of the user item the client saw last, so a retry or a second tab gets
     * `outdated` instead of dropping one more turn. The pending slot is cleared only when it belongs
     * to the dropped turn (its question or Confirm card), and returned so its photos can be deleted.
     *
     * @return array{ok: true, text: string, ask_context: array{prior_question: string}|null, dropped_pending: array<string, mixed>|null}|array{ok: false, reason: 'outdated'|'not_allowed', message: string}
     */
    public function rewindLastTurn(string $turnId, string $mode): array
    {
        $state = $this->state();
        $items = $state['transcript'];
        $index = self::lastTurnIndex($items);

        if ($index === null || ($items[$index]['id'] ?? null) !== $turnId) {
            return [
                'ok' => false,
                'reason' => 'outdated',
                'message' => 'That message is no longer the last one in this chat. Send it as a new message instead.',
            ];
        }

        $turn = array_slice($items, $index);
        $blocker = self::turnBlocker($turn, $mode);
        if ($blocker !== null) {
            return ['ok' => false, 'reason' => 'not_allowed', 'message' => $blocker];
        }

        $pending = $state['pending'];
        $dropPending = is_array($pending) && self::turnOwnsPending($turn, $pending);
        $user = $items[$index];

        $state['transcript'] = array_slice($items, 0, $index);
        if ($dropPending) {
            $state['pending'] = null;
        }
        $this->put($state);

        return [
            'ok' => true,
            'text' => (string) ($user['text'] ?? ''),
            'ask_context' => ($user['reply_to'] ?? null) === 'agent_ask'
                ? ['prior_question' => (string) ($user['prior_question'] ?? '')]
                : null,
            'dropped_pending' => $dropPending ? $pending : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function lastTurnIndex(array $items): ?int
    {
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if (($items[$i]['role'] ?? null) === 'user') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Why the turn (its user item first) can't be rewound in this mode, or null when it can.
     *
     * @param list<array<string, mixed>> $turn
     */
    public static function turnBlocker(array $turn, string $mode): ?string
    {
        $user = $turn[0] ?? [];
        if (($user['reply_to'] ?? null) === 'receipt') {
            return "That message answered a receipt question, so it can't be replaced. Send a new message instead.";
        }

        foreach ($turn as $item) {
            $kind = $item['kind'] ?? null;
            $applied = ($kind === 'confirm' && ($item['status'] ?? null) === 'confirmed')
                || ($kind === 'notice' && (($item['applied'] ?? false) === true || ($item['tone'] ?? null) === 'success'));
            if ($applied) {
                return "A change from that turn was already saved, so it can't be replaced. Send a new message instead.";
            }
        }

        if ($mode === self::REWIND_REGENERATE) {
            if ((int) ($user['photos'] ?? 0) > 0) {
                return "Receipt photo messages can't be regenerated. Send the photos again instead.";
            }
            foreach ($turn as $item) {
                if (($item['kind'] ?? null) === 'confirm') {
                    return "That answer proposed a change, so it can't be regenerated. Ask again instead.";
                }
            }
            if (trim((string) ($user['text'] ?? '')) === '') {
                return 'There is no message to regenerate.';
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $turn
     * @param array<string, mixed> $pending
     */
    private static function turnOwnsPending(array $turn, array $pending): bool
    {
        foreach ($turn as $item) {
            $kind = $item['kind'] ?? null;
            $status = $item['status'] ?? null;
            if ($kind === 'ask' && $status === 'pending') {
                return true;
            }
            if (
                $kind === 'confirm' && $status === 'pending'
                && is_string($pending['nonce'] ?? null) && ($item['pending_id'] ?? null) === $pending['nonce']
            ) {
                return true;
            }
        }

        return false;
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
