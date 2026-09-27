<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Tags\UpdateTagAction;
use App\Actions\Telegram\RunTelegramAgentAction;
use App\Actions\Telegram\TelegramAgentToolExecutor;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\Tag;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class NaturalLanguageHandler
{
    private const DEFAULT_EMOJI = '🏷';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly RunTelegramAgentAction $agent,
        private readonly TelegramAgentToolExecutor $toolExecutor,
        private readonly TelegramAiQuota $quota,
        private readonly CreateTagAction $createTag,
        private readonly ListTagsAction $listTags,
        private readonly UpdateTagAction $updateTag,
        private readonly TelegramChatSession $session,
    ) {}

    /**
     * Try AI agent handling. Returns true when the message was consumed
     * (including unavailable / quota exhausted responses).
     */
    public function tryHandle(
        User $user,
        int|string $chatId,
        string $text,
        int|string|null $telegramUserId = null,
    ): bool {
        $identity = $telegramUserId ?? $chatId;
        $this->session->touch($identity, $chatId);

        if (!$this->looksLikeNaturalLanguage($text)) {
            return false;
        }

        if ((string) config('services.xai.api_key') === '') {
            return false;
        }

        if (!$this->quota->hasQuota($user)) {
            $this->client->sendMessage(
                $chatId,
                $this->quota->exhaustedMessage(
                    $user,
                    'Use the menu buttons, type a quick-add, or try again after the reset. Settings in the Web UI shows your quota.',
                ),
            );
            $this->session->setSummary($identity, $chatId, 'AI quota exhausted.');

            return true;
        }

        $this->client->sendMessage($chatId, 'Thinking…');

        $result = $this->agent->execute($user, $chatId, $identity, $text);

        if (!$result['ok'] && ($result['status'] ?? '') === 'unavailable') {
            $this->client->sendMessage(
                $chatId,
                'AI is temporarily unavailable. Try /help, menu buttons, or -350 groceries.',
            );
            $this->session->setSummary($identity, $chatId, 'AI temporarily unavailable.');

            return true;
        }

        if (!$result['ok']) {
            $this->client->sendMessage(
                $chatId,
                "Didn't catch an action there. Try show recent, budgets, month summary, menu, -350 groceries, or /help.",
            );
            $this->session->setSummary($identity, $chatId, "Didn't catch an action.");

            return true;
        }

        $this->maybeHintLowRemaining($user, $chatId);

        return true;
    }

    /**
     * Continue the agent after an ask_user reply (free text or suggestion tap).
     */
    public function handlePendingAskAnswer(
        User $user,
        int|string $chatId,
        string $text,
        int|string|null $telegramUserId = null,
    ): bool {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'agent_ask') {
            return false;
        }

        $question = (string) ($pending['question'] ?? '');
        $this->session->clearPending($identity, $chatId);

        if ((string) config('services.xai.api_key') === '') {
            $this->client->sendMessage($chatId, 'AI is temporarily unavailable. Try /help or menu buttons.');

            return true;
        }

        if (!$this->quota->hasQuota($user)) {
            $this->client->sendMessage(
                $chatId,
                $this->quota->exhaustedMessage(
                    $user,
                    'Use the menu buttons, type a quick-add, or try again after the reset. Settings in the Web UI shows your quota.',
                ),
            );

            return true;
        }

        $this->client->sendMessage($chatId, 'Thinking…');

        $result = $this->agent->execute($user, $chatId, $identity, $text, [
            'prior_question' => $question,
        ]);

        if (!$result['ok'] && ($result['status'] ?? '') === 'unavailable') {
            $this->client->sendMessage(
                $chatId,
                'AI is temporarily unavailable. Try /help, menu buttons, or -350 groceries.',
            );

            return true;
        }

        $this->maybeHintLowRemaining($user, $chatId);

        return true;
    }

    public function handleAskChoice(
        User $user,
        int|string $chatId,
        int $choiceIndex,
        string $callbackId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'agent_ask') {
            $this->client->answerCallbackQuery($callbackId, 'That question expired.');

            return;
        }

        $suggestions = is_array($pending['suggestions'] ?? null) ? $pending['suggestions'] : [];
        if (!isset($suggestions[$choiceIndex]) || !is_string($suggestions[$choiceIndex])) {
            $this->client->answerCallbackQuery($callbackId, 'Invalid choice.');

            return;
        }

        $answer = $suggestions[$choiceIndex];
        $this->client->answerCallbackQuery($callbackId, $answer);
        $this->handlePendingAskAnswer($user, $chatId, $answer, $telegramUserId);
    }

    private function maybeHintLowRemaining(User $user, int|string $chatId): void
    {
        $hint = $this->quota->lowRemainingHint($user);
        if ($hint !== null) {
            $this->client->sendMessage($chatId, $hint);
        }
    }

    public function confirmCreateTags(
        User $user,
        int|string $chatId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];
        $this->session->clearPending($identity, $chatId);

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'create_tags') {
            $this->client->sendMessage($chatId, 'Nothing to confirm. Send a message like: create tags groceries, coffee');

            return;
        }

        $titles = is_array($pending['titles'] ?? null)
            ? array_values(array_filter($pending['titles'], 'is_string'))
            : [];
        $this->createTags($user, $chatId, $identity, $this->filterNewTagTitles($user, $titles));
    }

    public function cancelCreateTags(
        int|string $chatId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $this->session->clearPending($identity, $chatId);
        $this->session->setSummary($identity, $chatId, 'Tag creation cancelled.');
        $this->client->sendMessage($chatId, 'Cancelled');
    }

    public function pickRenameTag(
        User $user,
        int|string $chatId,
        int $tagId,
        string $callbackId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $session = $this->session->get($identity, $chatId);
        $pending = $session['pending'];
        $allowedIds = is_array($pending['item_ids'] ?? null) ? $pending['item_ids'] : [];
        $allowedIds = array_map('intval', $allowedIds);

        if (($pending['type'] ?? null) !== 'rename_pick' || !in_array($tagId, $allowedIds, true)) {
            $this->client->answerCallbackQuery($callbackId, 'That tag selection expired.');

            return;
        }

        $tag = Tag::query()->where('user_id', $user->id)->find($tagId);
        if (!$tag) {
            $this->client->answerCallbackQuery($callbackId, 'Tag not found.');
            $this->session->clearPending($identity, $chatId);

            return;
        }

        $this->session->setPending($identity, $chatId, [
            'type' => 'rename_title',
            'id' => $tag->id,
            'before' => $tag->title,
        ]);
        $this->session->setSummary($identity, $chatId, 'Waiting for a new title for tag ' . $tag->title . '.');
        $this->client->answerCallbackQuery($callbackId);
        $this->client->sendMessage($chatId, "Send the new title for {$tag->emoji} {$tag->title}.");
    }

    public function handlePendingRenameTitle(
        User $user,
        int|string $chatId,
        string $text,
        int|string|null $telegramUserId = null,
    ): bool {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'rename_title') {
            return false;
        }

        $title = mb_substr(trim($text), 0, 255);
        if ($title === '') {
            $this->client->sendMessage($chatId, 'Send a non-empty new tag title.');

            return true;
        }

        $tag = Tag::query()->where('user_id', $user->id)->find((int) ($pending['id'] ?? 0));
        if (!$tag) {
            $this->session->clearPending($identity, $chatId);
            $this->client->sendMessage($chatId, 'That tag no longer exists.');

            return true;
        }

        $this->session->setPending($identity, $chatId, [
            'type' => 'rename_tags',
            'proposals' => [[
                'id' => $tag->id,
                'before' => $tag->title,
                'after' => $title,
            ]],
        ]);
        $this->client->sendMessage(
            $chatId,
            "Rename tag:\n{$tag->title} → {$title}\n\nApply this change?",
            $this->support->nlRenameConfirmKeyboard(),
        );

        return true;
    }

    public function confirmRenameTags(
        User $user,
        int|string $chatId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];
        $this->session->clearPending($identity, $chatId);

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'rename_tags') {
            $this->client->sendMessage($chatId, 'Nothing to confirm.');

            return;
        }

        $rawProposals = is_array($pending['proposals'] ?? null) ? $pending['proposals'] : [];
        $proposals = [];
        foreach ($rawProposals as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }
            $id = is_numeric($proposal['id'] ?? null) ? (int) $proposal['id'] : 0;
            $before = is_string($proposal['before'] ?? null) ? (string) $proposal['before'] : '';
            $after = is_string($proposal['after'] ?? null) ? (string) $proposal['after'] : '';
            if ($id < 1 || $after === '') {
                continue;
            }
            $proposals[] = ['id' => $id, 'before' => $before, 'after' => $after];
        }

        if ($proposals === []) {
            $this->client->sendMessage($chatId, 'The rename preview is stale or has no valid changes.');

            return;
        }

        try {
            DB::transaction(function () use ($user, $proposals): void {
                foreach ($proposals as $proposal) {
                    $tag = Tag::query()
                        ->where('user_id', $user->id)
                        ->whereKey($proposal['id'])
                        ->first();

                    if (!$tag || mb_strtolower(trim($tag->title)) !== mb_strtolower(trim($proposal['before']))) {
                        throw new \RuntimeException('A tag changed while the preview was waiting.');
                    }

                    $this->updateTag->execute($user, $tag, [
                        'title' => $proposal['after'],
                        'emoji' => $tag->emoji,
                        'calc_balance' => $tag->calc_balance,
                        'parent_id' => $tag->parent_id,
                    ]);
                }
            });
        } catch (Throwable) {
            $this->client->sendMessage($chatId, 'Could not apply the rename preview; no changes were saved.');
            $this->session->setSummary($identity, $chatId, 'Rename failed because a tag changed or could not be updated.');

            return;
        }

        $this->saveTagListSession($identity, $chatId, $this->listTags->execute($user));
        $preview = implode("\n", array_map(
            static fn (array $proposal): string => $proposal['before'] . ' → ' . $proposal['after'],
            $proposals,
        ));
        $this->session->setSummary($identity, $chatId, 'Tags renamed.');
        $this->client->sendMessage($chatId, "✅ Renamed tags:\n" . $preview);
    }

    public function cancelRenameTags(
        int|string $chatId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $this->session->clearPending($identity, $chatId);
        $this->session->setSummary($identity, $chatId, 'Tag rename cancelled.');
        $this->client->sendMessage($chatId, 'Cancelled');
    }

    public function confirmAgentMutation(
        User $user,
        int|string $chatId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $pending = $this->session->get($identity, $chatId)['pending'];
        $this->session->clearPending($identity, $chatId);

        if (!is_array($pending) || ($pending['type'] ?? null) !== 'agent_mutation') {
            $this->client->sendMessage($chatId, 'Nothing to confirm.');

            return;
        }

        $this->toolExecutor->applyAgentMutation($user, $chatId, $identity, $pending);
    }

    public function cancelAgentMutation(
        int|string $chatId,
        int|string|null $telegramUserId = null,
    ): void {
        $identity = $telegramUserId ?? $chatId;
        $this->session->clearPending($identity, $chatId);
        $this->session->setSummary($identity, $chatId, 'Agent mutation cancelled.');
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
    private function createTags(User $user, int|string $chatId, int|string $identity, array $titles): void
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

        $this->saveTagListSession($identity, $chatId, $this->listTags->execute($user));
        $summary = '✅ Created tags: ' . implode(', ', $created);
        $this->session->setSummary($identity, $chatId, $summary);
        $this->client->sendMessage($chatId, $summary);
    }

    /**
     * @param Collection<int, Tag> $tags
     */
    private function saveTagListSession(int|string $identity, int|string $chatId, Collection $tags): void
    {
        $this->session->setDomain($identity, $chatId, 'tags');
        $this->session->setLastList($identity, $chatId, $tags->map(
            static fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'title' => (string) $tag->title,
                'emoji' => (string) $tag->emoji,
            ],
        )->values()->all());
    }
}
