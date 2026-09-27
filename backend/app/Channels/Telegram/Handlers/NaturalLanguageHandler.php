<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Telegram\InterpretTelegramTextAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\Tag;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Facades\Cache;

class NaturalLanguageHandler
{
    private const PENDING_TTL_MINUTES = 15;

    private const DEFAULT_EMOJI = '🏷';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly InterpretTelegramTextAction $interpretText,
        private readonly TelegramAiQuota $quota,
        private readonly CreateTagAction $createTag,
        private readonly ListTagsAction $listTags,
    ) {}

    /**
     * Try AI intent handling. Returns true when the message was consumed
     * (including "didn't understand" after a failed/low-confidence intent call).
     */
    public function tryHandle(User $user, int|string $chatId, string $text): bool
    {
        if (!$this->looksLikeNaturalLanguage($text)) {
            return false;
        }

        if (!$this->quota->hasQuota($user) || (string) config('services.xai.api_key') === '') {
            return false;
        }

        $this->client->sendMessage($chatId, 'Thinking…');

        $result = $this->interpretText->execute($user, $text);

        if ($result === null) {
            $this->client->sendMessage(
                $chatId,
                "Couldn't understand that. Try -350 groceries, /newtag, or /help.",
            );

            return true;
        }

        $this->quota->consume($user);

        if ($result['intent'] === 'none' || $result['confidence'] < 0.45) {
            $this->client->sendMessage(
                $chatId,
                "Didn't catch an action there. Try -350 groceries, /tags, /newtag 🏷 Title, or /help.",
            );

            return true;
        }

        if ($result['intent'] === 'list_tags') {
            // Reuse menu listing via a lightweight reply (avoid circular MenuHandler deps).
            $tags = $this->listTags->execute($user);
            if ($tags->isEmpty()) {
                $this->client->sendMessage($chatId, 'You have no tags yet. Try: create tags groceries, coffee');

                return true;
            }

            $lines = [];
            foreach ($tags->values() as $i => $tag) {
                $lines[] = ($i + 1) . ') ' . trim($tag->emoji . ' ' . $tag->title);
            }
            $this->client->sendMessage($chatId, "🏷 Your tags\n" . implode("\n", $lines));

            return true;
        }

        if ($result['intent'] === 'create_tags') {
            $titles = $this->filterNewTagTitles($user, $result['titles']);

            if ($titles === []) {
                $this->client->sendMessage($chatId, 'Those tags already exist (or none were left to create).');

                return true;
            }

            if ($result['needs_confirm']) {
                $this->putPendingCreateTags($chatId, $titles);
                $label = implode(', ', $titles);
                $this->client->sendMessage(
                    $chatId,
                    "Create tags: {$label}?",
                    $this->support->nlCreateTagsKeyboard(),
                );

                return true;
            }

            $this->createTags($user, $chatId, $titles);

            return true;
        }

        return true;
    }

    public function confirmCreateTags(User $user, int|string $chatId): void
    {
        $titles = $this->pullPendingCreateTags($chatId);

        if ($titles === null || $titles === []) {
            $this->client->sendMessage($chatId, 'Nothing to confirm. Send a message like: create tags groceries, coffee');

            return;
        }

        $this->createTags($user, $chatId, $this->filterNewTagTitles($user, $titles));
    }

    public function cancelCreateTags(int|string $chatId): void
    {
        Cache::forget($this->pendingKey($chatId));
        $this->client->sendMessage($chatId, 'Cancelled');
    }

    /**
     * Heuristic: skip AI for obvious quick-add-shaped leftovers and tiny noise.
     */
    private function looksLikeNaturalLanguage(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === '' || mb_strlen($trimmed) < 3) {
            return false;
        }

        if (str_starts_with($trimmed, '/')) {
            return false;
        }

        // Pure amounts already handled by quick-add; anything with letters may be intent.
        return (bool) preg_match('/\p{L}/u', $trimmed);
    }

    /**
     * @param list<string> $titles
     * @return list<string>
     */
    private function filterNewTagTitles(User $user, array $titles): array
    {
        $existing = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $existing[mb_strtolower(trim((string) $tag->title))] = true;
        }

        $out = [];
        foreach ($titles as $title) {
            $key = mb_strtolower(trim($title));
            if ($key === '' || isset($existing[$key])) {
                continue;
            }
            $existing[$key] = true;
            $out[] = mb_substr(trim($title), 0, 255);
        }

        return $out;
    }

    /**
     * @param list<string> $titles
     */
    private function createTags(User $user, int|string $chatId, array $titles): void
    {
        if ($titles === []) {
            $this->client->sendMessage($chatId, 'Those tags already exist (or none were left to create).');

            return;
        }

        $created = [];
        foreach ($titles as $title) {
            $tag = $this->createTag->execute($user, [
                'title' => $title,
                'emoji' => self::DEFAULT_EMOJI,
                'parent_id' => null,
                'calc_balance' => true,
            ]);
            $created[] = trim($tag->emoji . ' ' . $tag->title);
        }

        $this->client->sendMessage($chatId, '✅ Created tags: ' . implode(', ', $created));
    }

    /**
     * @param list<string> $titles
     */
    private function putPendingCreateTags(int|string $chatId, array $titles): void
    {
        Cache::put($this->pendingKey($chatId), ['titles' => $titles], now()->addMinutes(self::PENDING_TTL_MINUTES));
    }

    /**
     * @return list<string>|null
     */
    private function pullPendingCreateTags(int|string $chatId): ?array
    {
        $data = Cache::pull($this->pendingKey($chatId));
        if (!is_array($data) || !isset($data['titles']) || !is_array($data['titles'])) {
            return null;
        }

        return array_values(array_filter($data['titles'], 'is_string'));
    }

    private function pendingKey(int|string $chatId): string
    {
        return "telegram_nl_create_tags:{$chatId}";
    }
}
