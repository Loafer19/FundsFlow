<?php

namespace App\Actions\Telegram;

use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InterpretTelegramTextAction
{
    public function __construct(
        private readonly TelegramAiQuota $quota,
    ) {}

    /**
     * Classify free-form Telegram text into structured intent and UI slots.
     * This action never mutates account data.
     *
     * Menu alignment (AI must not invent callback_data / press Telegram buttons):
     * intents map 1:1 onto MenuHandler / TelegramSupport helpers; ui-slots may only
     * re-attach prebuilt keyboards from TelegramSupport (e.g. menuKeyboard).
     * NL intents: help, show_menu, list_recent, list_budgets, list_recurring,
     * open_web, list_tags, create_tags, rename_tags, none.
     * Out of scope for now: period_summary / Month analytics.
     *
     * @param array<string, mixed> $sessionContext
     * @return array{
     *     intent: 'help'|'show_menu'|'list_recent'|'list_budgets'|'list_recurring'|'open_web'|'create_tags'|'list_tags'|'rename_tags'|'none',
     *     titles: list<string>,
     *     proposals: list<array{id: int|null, index: int|null, before: string, after: string}>,
     *     confidence: float,
     *     needs_confirm: bool,
     *     ui: array{type: 'confirm'|'pick_one'|'none', item_ids: list<int>}
     * }|null Null when AI is unavailable or the call fails (does not burn quota).
     */
    public function execute(User $user, string $text, array $sessionContext = []): ?array
    {
        $apiKey = (string) config('services.xai.api_key');
        $baseUrl = rtrim((string) config('services.xai.base_url', 'https://api.x.ai/v1'), '/');
        $model = (string) config('services.xai.text_model', 'grok-2-1212');

        if ($apiKey === '') {
            return null;
        }

        $contextJson = json_encode([
            'domain' => $sessionContext['domain'] ?? null,
            'last_list' => $sessionContext['last_list'] ?? [],
            'last_bot_summary' => $sessionContext['last_bot_summary'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = is_string($contextJson) ? $contextJson : '{}';

        $system = <<<PROMPT
You classify short messages to a personal finance Telegram bot (FundsFlow).
Reply with ONLY a JSON object (no markdown):
{
  "intent": "help" | "show_menu" | "list_recent" | "list_budgets" | "list_recurring" | "open_web" | "create_tags" | "list_tags" | "rename_tags" | "none",
  "titles": string[],
  "proposals": [{"id": number|null, "index": number|null, "before": string, "after": string}],
  "confidence": number,
  "needs_confirm": boolean,
  "ui": {"type": "confirm" | "pick_one" | "none", "item_ids": number[]}
}

Session context (account data is authoritative; do not invent tag ids):
{$contextJson}

Rules:
- help: asks what the bot can do, its capabilities, or how to use it.
- show_menu: asks to show/open the reply menu or keyboard buttons (Recent, Budgets, Tags, …).
- list_recent: asks to show recent / latest transactions (same as the Recent menu button).
- list_budgets: asks to show budgets / budget progress (same as Budgets).
- list_recurring: asks to show recurring rules / subscriptions (same as Recurring).
- open_web: asks to open the website / Web UI / mini app (same as Web UI / /app).
- list_tags: asks to show/list tags.
- create_tags: user wants to create one or more tags/categories. Put cleaned titles in
  titles (1–10 items, no emojis unless clearly part of the name). Keep the user's wording.
- rename_tags: user wants to rename one or more existing tags. For each proposal, use an id
  from last_list when available. If the user refers to an ordinal such as "second", use
  index=2 and the handler will resolve it against last_list. Copy the exact current name
  into before and put the requested new name in after. "rename tags to Ukrainian" means
  propose Ukrainian translations for the tags in last_list. Do not invent tags or ids.
- Ordinals and follow-ups refer to last_list. Keep the domain sticky across short follow-ups.
- If a rename request identifies a tag but does not provide a new title, use ui.type="pick_one"
  only when the user must choose among last_list; otherwise use ui.type="none".
- ui.item_ids may contain only ids explicitly present in last_list. The handler validates them.
- needs_confirm must be true for create_tags and rename_tags. It must be false for
  help/show_menu/list_*/open_web/none.
- ui.type should be confirm for a mutation preview, pick_one when a tag selection is required,
  and none for read-only or unclear requests. Do not invent callback_data.
- none: unrelated chat, unclear requests, or analytics/month summaries (not supported via NL yet).
- confidence: 0–1.
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->acceptJson()
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 700,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('InterpretTelegramTextAction: xAI HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (!is_string($content) || trim($content) === '') {
                return null;
            }

            return $this->parseModelJson($content);
        } catch (Throwable $e) {
            Log::warning('InterpretTelegramTextAction: exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function quota(): TelegramAiQuota
    {
        return $this->quota;
    }

    /**
     * @return array{
     *     intent: 'help'|'show_menu'|'list_recent'|'list_budgets'|'list_recurring'|'open_web'|'create_tags'|'list_tags'|'rename_tags'|'none',
     *     titles: list<string>,
     *     proposals: list<array{id: int|null, index: int|null, before: string, after: string}>,
     *     confidence: float,
     *     needs_confirm: bool,
     *     ui: array{type: 'confirm'|'pick_one'|'none', item_ids: list<int>}
     * }|null
     */
    private function parseModelJson(string $content): ?array
    {
        $json = trim($content);

        if (preg_match('/\{[\s\S]*\}/', $json, $matches)) {
            $json = $matches[0];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $intent = $decoded['intent'] ?? 'none';
        $allowedIntents = [
            'help',
            'show_menu',
            'list_recent',
            'list_budgets',
            'list_recurring',
            'open_web',
            'create_tags',
            'list_tags',
            'rename_tags',
            'none',
        ];
        if (!in_array($intent, $allowedIntents, true)) {
            $intent = 'none';
        }

        $titles = $this->parseTitles($decoded['titles'] ?? []);
        $proposals = $this->parseProposals($decoded['proposals'] ?? []);

        if ($intent === 'create_tags' && $titles === []) {
            $intent = 'none';
        }
        if ($intent === 'rename_tags' && $proposals === []) {
            // Keep the intent: the handler may turn a pick_one UI slot into a follow-up.
            $uiType = data_get($decoded, 'ui.type');
            if ($uiType !== 'pick_one') {
                $intent = 'none';
            }
        }

        $confidence = $decoded['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0;

        $needsConfirm = (bool) ($decoded['needs_confirm'] ?? true);
        if ($intent === 'create_tags') {
            // Preserve the existing behavior: only a very confident explicit create may skip confirmation.
            if ($confidence < 0.75) {
                $needsConfirm = true;
            }
        } elseif ($intent === 'rename_tags') {
            // Renames always show a before → after preview before mutation.
            $needsConfirm = true;
        } else {
            $needsConfirm = false;
        }

        $uiType = data_get($decoded, 'ui.type', 'none');
        if (!in_array($uiType, ['confirm', 'pick_one', 'none'], true)) {
            $uiType = 'none';
        }

        if (($intent === 'rename_tags' && $proposals !== []) || $intent === 'create_tags') {
            $uiType = $needsConfirm ? 'confirm' : 'none';
        }
        if ($intent === 'rename_tags' && $proposals === [] && $uiType !== 'pick_one') {
            $uiType = 'none';
        }

        $itemIds = data_get($decoded, 'ui.item_ids', []);
        if (!is_array($itemIds)) {
            $itemIds = [];
        }
        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): ?int => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
            $itemIds,
        ))));

        return [
            'intent' => $intent,
            'titles' => $titles,
            'proposals' => $proposals,
            'confidence' => $confidence,
            'needs_confirm' => $needsConfirm,
            'ui' => [
                'type' => $uiType,
                'item_ids' => $itemIds,
            ],
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function parseTitles(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $titles = array_values(array_unique(array_filter(array_map(
            static function (mixed $title): ?string {
                if (!is_string($title)) {
                    return null;
                }
                $title = trim($title);
                if ($title === '') {
                    return null;
                }

                return mb_substr($title, 0, 255);
            },
            $raw,
        ))));

        return array_slice($titles, 0, 10);
    }

    /**
     * @param mixed $raw
     * @return list<array{id: int|null, index: int|null, before: string, after: string}>
     */
    private function parseProposals(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $proposals = [];
        foreach ($raw as $proposal) {
            if (!is_array($proposal)) {
                continue;
            }

            $id = is_numeric($proposal['id'] ?? null) && (int) $proposal['id'] > 0
                ? (int) $proposal['id']
                : null;
            $index = is_numeric($proposal['index'] ?? null) && (int) $proposal['index'] > 0
                ? (int) $proposal['index']
                : null;
            $before = is_string($proposal['before'] ?? null) ? trim($proposal['before']) : '';
            $after = is_string($proposal['after'] ?? null) ? trim($proposal['after']) : '';

            if (($id === null && $index === null) || $after === '') {
                continue;
            }

            $proposals[] = [
                'id' => $id,
                'index' => $index,
                'before' => mb_substr($before, 0, 255),
                'after' => mb_substr($after, 0, 255),
            ];
        }

        return array_slice($proposals, 0, 50);
    }
}
