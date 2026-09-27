<?php

namespace App\Channels\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived per-chat Telegram assistant context.
 *
 * The identity is normally the Telegram user id. Chat id is included so a
 * user's private chat and group chats cannot share pending mutations.
 */
final class TelegramChatSession
{
    private const TTL_MINUTES = 20;

    /**
     * @return array{
     *     domain: 'tags'|'budgets'|'transactions'|null,
     *     last_list: list<array{id: int, title: string, emoji?: string}>,
     *     pending: array<string, mixed>|null,
     *     last_bot_summary: string,
     *     expires_at: string|null
     * }
     */
    public function get(int|string $telegramUserId, int|string|null $chatId = null): array
    {
        $data = Cache::get($this->key($telegramUserId, $chatId));

        if (!is_array($data)) {
            return $this->defaults();
        }

        return $this->normalize($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function put(int|string $telegramUserId, int|string|null $chatId, array $data): array
    {
        $session = $this->normalize($data);
        $session['expires_at'] = now()->addMinutes(self::TTL_MINUTES)->toIso8601String();

        Cache::put(
            $this->key($telegramUserId, $chatId),
            $session,
            now()->addMinutes(self::TTL_MINUTES),
        );

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function touch(int|string $telegramUserId, int|string|null $chatId = null): array
    {
        return $this->put($telegramUserId, $chatId, $this->get($telegramUserId, $chatId));
    }

    public function clear(int|string $telegramUserId, int|string|null $chatId = null): void
    {
        Cache::forget($this->key($telegramUserId, $chatId));
    }

    public function setDomain(
        int|string $telegramUserId,
        int|string|null $chatId,
        ?string $domain,
    ): array {
        $session = $this->get($telegramUserId, $chatId);
        $session['domain'] = in_array($domain, ['tags', 'budgets', 'transactions'], true) ? $domain : null;

        return $this->put($telegramUserId, $chatId, $session);
    }

    /**
     * @param list<array{id: int, title: string, emoji?: string}> $items
     * @return array<string, mixed>
     */
    public function setLastList(
        int|string $telegramUserId,
        int|string|null $chatId,
        array $items,
    ): array {
        $session = $this->get($telegramUserId, $chatId);
        $session['last_list'] = array_values(array_filter(array_map(
            static function (mixed $item): ?array {
                if (!is_array($item) || !isset($item['id'], $item['title'])) {
                    return null;
                }

                $id = filter_var($item['id'], FILTER_VALIDATE_INT);
                $title = trim((string) $item['title']);
                if ($id === false || $id < 1 || $title === '') {
                    return null;
                }

                $out = [
                    'id' => (int) $id,
                    'title' => mb_substr($title, 0, 255),
                ];

                if (isset($item['emoji']) && is_string($item['emoji']) && trim($item['emoji']) !== '') {
                    $out['emoji'] = mb_substr(trim($item['emoji']), 0, 255);
                }

                return $out;
            },
            $items,
        )));

        return $this->put($telegramUserId, $chatId, $session);
    }

    /**
     * @param array<string, mixed>|null $pending
     * @return array<string, mixed>
     */
    public function setPending(
        int|string $telegramUserId,
        int|string|null $chatId,
        ?array $pending,
    ): array {
        $session = $this->get($telegramUserId, $chatId);
        $session['pending'] = $pending;

        return $this->put($telegramUserId, $chatId, $session);
    }

    public function clearPending(int|string $telegramUserId, int|string|null $chatId = null): array
    {
        return $this->setPending($telegramUserId, $chatId, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function setSummary(
        int|string $telegramUserId,
        int|string|null $chatId,
        string $summary,
    ): array {
        $session = $this->get($telegramUserId, $chatId);
        $session['last_bot_summary'] = mb_substr(trim($summary), 0, 2000);

        return $this->put($telegramUserId, $chatId, $session);
    }

    /**
     * @return array{
     *     domain: 'tags'|'budgets'|'transactions'|null,
     *     last_list: list<array{id: int, title: string, emoji?: string}>,
     *     pending: array<string, mixed>|null,
     *     last_bot_summary: string,
     *     expires_at: string|null
     * }
     */
    private function defaults(): array
    {
        return [
            'domain' => null,
            'last_list' => [],
            'pending' => null,
            'last_bot_summary' => '',
            'expires_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $defaults = $this->defaults();
        $domain = $data['domain'] ?? null;
        $lastList = is_array($data['last_list'] ?? null) ? $data['last_list'] : [];
        $pending = is_array($data['pending'] ?? null) ? $data['pending'] : null;
        $summary = is_string($data['last_bot_summary'] ?? null) ? $data['last_bot_summary'] : '';
        $expiresAt = is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null;

        $session = [
            'domain' => in_array($domain, ['tags', 'budgets', 'transactions'], true) ? $domain : null,
            'last_list' => [],
            'pending' => $pending,
            'last_bot_summary' => mb_substr($summary, 0, 2000),
            'expires_at' => $expiresAt,
        ];

        foreach ($lastList as $item) {
            if (!is_array($item) || !isset($item['id'], $item['title'])) {
                continue;
            }

            $id = filter_var($item['id'], FILTER_VALIDATE_INT);
            $title = trim((string) $item['title']);
            if ($id === false || $id < 1 || $title === '') {
                continue;
            }

            $normalized = [
                'id' => (int) $id,
                'title' => mb_substr($title, 0, 255),
            ];
            if (isset($item['emoji']) && is_string($item['emoji']) && trim($item['emoji']) !== '') {
                $normalized['emoji'] = mb_substr(trim($item['emoji']), 0, 255);
            }
            $session['last_list'][] = $normalized;
        }

        return array_replace($defaults, $session);
    }

    private function key(int|string $telegramUserId, int|string|null $chatId): string
    {
        return 'telegram_assistant_session:' . (string) $telegramUserId . ':' . (string) ($chatId ?? $telegramUserId);
    }
}
