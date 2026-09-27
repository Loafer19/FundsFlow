<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Tags\UpdateTagAction;
use App\Actions\Telegram\InterpretTelegramTextAction;
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
        private readonly InterpretTelegramTextAction $interpretText,
        private readonly TelegramAiQuota $quota,
        private readonly CreateTagAction $createTag,
        private readonly ListTagsAction $listTags,
        private readonly UpdateTagAction $updateTag,
        private readonly TelegramChatSession $session,
        private readonly MenuHandler $menuHandler,
    ) {}

    /**
     * Try AI intent handling. Returns true when the message was consumed
     * (including "didn't understand" after a failed/low-confidence intent call).
     */
    public function tryHandle(
        User $user,
        int|string $chatId,
        string $text,
        int|string|null $telegramUserId = null,
    ): bool {
        $identity = $telegramUserId ?? $chatId;
        $this->session->touch($identity, $chatId);

        // Keep common help requests free of AI quota. /help is handled earlier too.
        if ($this->looksLikeHelp($text)) {
            $this->support->sendHelp($chatId);
            $this->session->setSummary($identity, $chatId, 'Help and capabilities sent.');

            return true;
        }

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

        $session = $this->session->get($identity, $chatId);

        // A first rename request still gets authoritative tag ids in the prompt.
        if ($this->looksLikeTagRenameRequest($text) && $session['last_list'] === []) {
            $this->saveTagListSession($identity, $chatId, $this->listTags->execute($user));
            $session = $this->session->get($identity, $chatId);
        }

        $this->client->sendMessage($chatId, 'Thinking…');

        $result = $this->interpretText->execute($user, $text, $session);

        if ($result === null) {
            $this->client->sendMessage(
                $chatId,
                "Couldn't understand that. Try -350 groceries, /newtag, or /help.",
            );
            $this->session->setSummary($identity, $chatId, "Couldn't understand the request.");

            return true;
        }

        $this->quota->consume($user);

        $this->applyIntent($user, $chatId, $identity, $result);
        $this->maybeHintLowRemaining($user, $chatId);

        return true;
    }

    /**
     * @param array{
     *     intent: string,
     *     titles: list<string>,
     *     proposals: list<array{id: int|null, index: int|null, before: string, after: string}>,
     *     confidence: float,
     *     needs_confirm: bool,
     *     ui: array{type: string, item_ids: list<int>}
     * } $result
     */
    private function applyIntent(
        User $user,
        int|string $chatId,
        int|string $identity,
        array $result,
    ): void {
        if ($result['intent'] === 'help') {
            $this->support->sendHelp($chatId);
            $this->session->setSummary($identity, $chatId, 'Help and capabilities sent.');

            return;
        }

        if ($result['intent'] === 'none' || $result['confidence'] < 0.45) {
            $this->client->sendMessage(
                $chatId,
                "Didn't catch an action there. Try show recent, budgets, month summary, menu, -350 groceries, or /help.",
            );
            $this->session->setSummary($identity, $chatId, "Didn't catch an action.");

            return;
        }

        if ($result['intent'] === 'show_menu') {
            $this->client->sendMessage(
                $chatId,
                'Here is the menu — tap a button, or keep chatting in plain language.',
                $this->support->menuKeyboard(),
            );
            $this->session->setSummary($identity, $chatId, 'Reply menu sent.');

            return;
        }

        if ($result['intent'] === 'list_recent') {
            $this->menuHandler->sendRecent($user, $chatId, $identity);

            return;
        }

        if ($result['intent'] === 'list_budgets') {
            $this->menuHandler->sendBudgets($user, $chatId, $identity);

            return;
        }

        if ($result['intent'] === 'list_recurring') {
            $this->menuHandler->sendRecurring($user, $chatId);
            $this->session->setSummary($identity, $chatId, 'Recurring rules listed.');

            return;
        }

        if ($result['intent'] === 'period_summary') {
            $this->menuHandler->sendMonthSummary($user, $chatId, $identity);

            return;
        }

        if ($result['intent'] === 'open_web') {
            $this->support->sendMiniAppHint($chatId);
            $this->session->setSummary($identity, $chatId, 'Web UI hint sent.');

            return;
        }

        if ($result['intent'] === 'list_tags') {
            $this->menuHandler->sendTags($user, $chatId, $identity);

            return;
        }

        if ($result['intent'] === 'create_tags') {
            $this->handleCreateTags($user, $chatId, $identity, $result['titles'], $result['needs_confirm']);

            return;
        }

        if ($result['intent'] === 'rename_tags') {
            $proposals = $this->resolveRenameProposals($user, $identity, $chatId, $result['proposals']);

            if ($proposals === [] && $result['ui']['type'] === 'pick_one') {
                $this->promptRenamePick($chatId, $identity, $result['ui']['item_ids']);

                return;
            }

            if ($proposals === []) {
                $this->client->sendMessage(
                    $chatId,
                    'I could not match a tag and a new title. Try: rename the second to Groceries, or list tags first.',
                );
                $this->session->setSummary($identity, $chatId, 'Rename request had no valid tag proposals.');

                return;
            }

            $this->putPendingRename($identity, $chatId, $proposals);
            $this->client->sendMessage(
                $chatId,
                "Rename tags:\n" . $this->formatRenamePreview($proposals) . "\n\nApply these changes?",
                $this->support->nlRenameConfirmKeyboard(),
            );
            $this->session->setSummary($identity, $chatId, 'Rename preview shown.');
        }
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
        $proposals = $this->resolveRenameProposals($user, $identity, $chatId, $rawProposals);
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
        $this->session->setSummary($identity, $chatId, 'Tags renamed.');
        $this->client->sendMessage($chatId, '✅ Renamed tags:\n' . $this->formatRenamePreview($proposals));
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

    private function looksLikeHelp(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        return (bool) preg_match('/^(help|what can you do|what can you help with|show capabilities|capabilities|how can you help)\b/u', $text);
    }

    private function looksLikeTagRenameRequest(string $text): bool
    {
        return (bool) preg_match('/\b(rename|renaming|переймен|переназв|україн|ukrainian)\p{L}*/ui', $text);
    }

    /**
     * @param list<string> $titles
     */
    private function handleCreateTags(
        User $user,
        int|string $chatId,
        int|string $identity,
        array $titles,
        bool $needsConfirm,
    ): void {
        $titles = $this->filterNewTagTitles($user, $titles);
        $this->session->setDomain($identity, $chatId, 'tags');

        if ($titles === []) {
            $this->client->sendMessage($chatId, 'Those tags already exist (or none were left to create).');
            $this->session->setSummary($identity, $chatId, 'No new tags were created.');

            return;
        }

        if ($needsConfirm) {
            $this->session->setPending($identity, $chatId, [
                'type' => 'create_tags',
                'titles' => $titles,
            ]);
            $label = implode(', ', $titles);
            $this->client->sendMessage(
                $chatId,
                "Create tags: {$label}?",
                $this->support->nlCreateTagsKeyboard(),
            );
            $this->session->setSummary($identity, $chatId, 'Create-tag preview shown.');

            return;
        }

        $this->createTags($user, $chatId, $identity, $titles);
    }

    /**
     * @param list<array{id: int|null, index: int|null, before: string, after: string}> $rawProposals
     * @return list<array{id: int, before: string, after: string}>
     */
    private function resolveRenameProposals(
        User $user,
        int|string $identity,
        int|string $chatId,
        array $rawProposals,
    ): array {
        $session = $this->session->get($identity, $chatId);
        $lastList = $session['last_list'];
        $byId = [];
        foreach ($lastList as $index => $item) {
            $byId[(int) $item['id']] = ['index' => $index + 1, 'title' => $item['title']];
        }

        $tags = $this->listTags->execute($user)->keyBy('id');
        $out = [];
        $usedAfter = [];

        foreach ($rawProposals as $proposal) {
            $id = is_numeric($proposal['id'] ?? null) ? (int) $proposal['id'] : null;
            $index = is_numeric($proposal['index'] ?? null) ? (int) $proposal['index'] : null;

            if (($id === null || !isset($byId[$id])) && $index !== null && isset($lastList[$index - 1])) {
                $id = (int) $lastList[$index - 1]['id'];
            }
            if ($id === null || !isset($byId[$id])) {
                continue;
            }

            /** @var Tag|null $tag */
            $tag = $tags->get($id);
            if (!$tag) {
                continue;
            }

            $before = trim((string) ($proposal['before'] ?? ''));
            if ($before !== '' && mb_strtolower($before) !== mb_strtolower($tag->title)) {
                continue;
            }

            $after = mb_substr(trim((string) ($proposal['after'] ?? '')), 0, 255);
            if ($after === '' || mb_strtolower($after) === mb_strtolower($tag->title)) {
                continue;
            }

            $afterKey = mb_strtolower($after);
            $duplicate = $tags->contains(function (Tag $other) use ($afterKey, $id): bool {
                return (int) $other->id !== $id && mb_strtolower(trim($other->title)) === $afterKey;
            });
            if ($duplicate || isset($usedAfter[$afterKey])) {
                continue;
            }

            $usedAfter[$afterKey] = true;
            $out[] = [
                'id' => $id,
                'before' => $tag->title,
                'after' => $after,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $requestedIds
     */
    private function promptRenamePick(
        int|string $chatId,
        int|string $identity,
        array $requestedIds,
    ): void {
        $session = $this->session->get($identity, $chatId);
        $available = $session['last_list'];
        $availableIds = array_map(static fn (array $item): int => (int) $item['id'], $available);
        $ids = array_values(array_intersect($requestedIds, $availableIds));
        if ($ids === []) {
            $ids = $availableIds;
        }

        if ($ids === []) {
            $this->client->sendMessage($chatId, 'No tags are available to rename.');

            return;
        }

        $items = array_values(array_filter($available, static fn (array $item): bool => in_array((int) $item['id'], $ids, true)));
        $this->session->setPending($identity, $chatId, [
            'type' => 'rename_pick',
            'item_ids' => $ids,
        ]);
        $this->client->sendMessage($chatId, 'Which tag should I rename?', $this->support->nlRenamePickKeyboard($items));
    }

    /**
     * @param list<array{id: int, before: string, after: string}> $proposals
     */
    private function formatRenamePreview(array $proposals): string
    {
        return implode("\n", array_map(
            static fn (array $proposal): string => $proposal['before'] . ' → ' . $proposal['after'],
            $proposals,
        ));
    }

    /**
     * @param list<array{id: int, before: string, after: string}> $proposals
     */
    private function putPendingRename(int|string $identity, int|string $chatId, array $proposals): void
    {
        $this->session->setPending($identity, $chatId, [
            'type' => 'rename_tags',
            'proposals' => $proposals,
        ]);
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
