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
     * Classify free-form Telegram text into a small intent set (no mutations).
     *
     * @return array{
     *     intent: 'create_tags'|'list_tags'|'none',
     *     titles: list<string>,
     *     confidence: float,
     *     needs_confirm: bool
     * }|null Null when AI is unavailable or the call fails (does not burn quota).
     */
    public function execute(User $user, string $text): ?array
    {
        $apiKey = (string) config('services.xai.api_key');
        $baseUrl = rtrim((string) config('services.xai.base_url', 'https://api.x.ai/v1'), '/');
        $model = (string) config('services.xai.text_model', 'grok-2-1212');

        if ($apiKey === '') {
            return null;
        }

        $system = <<<'PROMPT'
You classify short messages to a personal finance Telegram bot (FundsFlow).
Reply with ONLY a JSON object (no markdown):
{
  "intent": "create_tags" | "list_tags" | "none",
  "titles": string[],
  "confidence": number,
  "needs_confirm": boolean
}
Rules:
- create_tags: user wants to create one or more tags/categories. Put cleaned tag titles in "titles" (1–10 items, no emojis unless clearly part of the name). Languages: accept Ukrainian/Russian/English; keep titles in the user's wording (trimmed).
- list_tags: user asks to show their tags.
- none: anything else (transactions, chit-chat, unclear).
- needs_confirm: true unless the message clearly and unambiguously asks to create those tags (e.g. "create tags A, B" / "створи теги A, B"). Prefer true when unsure.
- confidence: 0–1.
- Do not invent titles that were not mentioned for create_tags.
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->acceptJson()
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 300,
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
     *     intent: 'create_tags'|'list_tags'|'none',
     *     titles: list<string>,
     *     confidence: float,
     *     needs_confirm: bool
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
        if (!in_array($intent, ['create_tags', 'list_tags', 'none'], true)) {
            $intent = 'none';
        }

        $titles = $decoded['titles'] ?? [];
        if (!is_array($titles)) {
            $titles = [];
        }
        $titles = array_values(array_unique(array_filter(array_map(
            static function ($title) {
                if (!is_string($title)) {
                    return null;
                }
                $title = trim($title);
                if ($title === '') {
                    return null;
                }

                return mb_substr($title, 0, 255);
            },
            $titles,
        ))));
        $titles = array_slice($titles, 0, 10);

        if ($intent === 'create_tags' && $titles === []) {
            $intent = 'none';
        }

        $confidence = $decoded['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0;

        $needsConfirm = (bool) ($decoded['needs_confirm'] ?? true);
        if ($intent === 'create_tags') {
            // Always confirm mutations for MVP safety unless model is very sure and said no confirm.
            if ($confidence < 0.75) {
                $needsConfirm = true;
            }
        } else {
            $needsConfirm = false;
        }

        return [
            'intent' => $intent,
            'titles' => $titles,
            'confidence' => $confidence,
            'needs_confirm' => $needsConfirm,
        ];
    }
}
