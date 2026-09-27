<?php

namespace App\Actions\Telegram;

use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeReceiptAction
{
    public function __construct(
        private readonly TelegramAiQuota $quota,
    ) {}

    /**
     * Analyze a receipt image via xAI Grok vision (OpenAI-compatible chat completions).
     *
     * @return array{
     *     amount: ?float,
     *     at: ?string,
     *     note: ?string,
     *     suggested_tag_titles: list<string>,
     *     category: ?string,
     *     needs_price: bool,
     *     confidence: ?float
     * }|null Null when the call fails or is not configured (does not burn quota).
     */
    public function execute(User $user, string $imageBytes, string $mime, ?string $captionHint = null): ?array
    {
        $apiKey = (string) config('services.xai.api_key');
        $baseUrl = rtrim((string) config('services.xai.base_url', 'https://api.x.ai/v1'), '/');
        $model = (string) config('services.xai.vision_model', 'grok-4');

        if ($apiKey === '') {
            Log::warning('AnalyzeReceiptAction: XAI_API_KEY is empty');

            return null;
        }

        if (!$this->isVisionMime($mime)) {
            return null;
        }

        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);
        $today = $user->todayDateString();
        $hint = $captionHint !== null && trim($captionHint) !== ''
            ? "\nUser caption hint (may lack amount): " . trim($captionHint)
            : '';

        $system = <<<'PROMPT'
You extract structured data from a receipt or payment screenshot for a personal finance app.
FundsFlow convention: expenses are negative amounts, income is positive.
Reply with ONLY a JSON object (no markdown) matching this schema:
{
  "amount": number|null,
  "at": "YYYY-MM-DD"|null,
  "note": string|null,
  "suggested_tag_titles": string[],
  "category": string|null,
  "needs_price": boolean,
  "confidence": number|null
}
Rules:
- amount: total paid as a signed number. Prefer expense (negative) for purchases. Use null if the total is missing, ambiguous, or a food/menu photo without a clear price.
- at: receipt date if clearly visible, else null (caller uses today).
- note: short merchant/place or description, max ~80 chars, no currency symbols required.
- suggested_tag_titles: 0–3 short English tag names that fit (e.g. "Food", "Transport", "Groceries").
- category: coarse label like food, groceries, transport, utilities, health, shopping, entertainment, other — or null.
- needs_price: true when amount is null OR the image is food/unclear and the user must type the price. Never invent a total.
- confidence: 0–1 how sure you are about the amount (null if needs_price).
PROMPT;

        $userText = "Today (user timezone date) is {$today}. Extract the receipt fields.{$hint}";

        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->acceptJson()
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 500,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $system,
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => $dataUrl,
                                        'detail' => 'high',
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $userText,
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('AnalyzeReceiptAction: xAI HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (!is_string($content) || trim($content) === '') {
                return null;
            }

            return $this->parseModelJson($content, $today);
        } catch (Throwable $e) {
            Log::warning('AnalyzeReceiptAction: exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function dailyLimit(): int
    {
        return $this->quota->dailyLimit();
    }

    public function remainingQuota(User $user): int
    {
        return $this->quota->remaining($user);
    }

    public function hasQuota(User $user): bool
    {
        return $this->quota->hasQuota($user);
    }

    /**
     * Count a successful AI call toward the daily limit (user calendar day).
     */
    public function consumeQuota(User $user): void
    {
        $this->quota->consume($user);
    }

    public function quotaKey(User $user): string
    {
        return $this->quota->key($user);
    }

    public function quota(): TelegramAiQuota
    {
        return $this->quota;
    }

    public function isVisionMime(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png'], true);
    }

    /**
     * @return array{
     *     amount: ?float,
     *     at: ?string,
     *     note: ?string,
     *     suggested_tag_titles: list<string>,
     *     category: ?string,
     *     needs_price: bool,
     *     confidence: ?float
     * }|null
     */
    private function parseModelJson(string $content, string $fallbackDate): ?array
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

        $amount = $decoded['amount'] ?? null;
        $amount = is_numeric($amount) ? (float) $amount : null;

        if ($amount === 0.0) {
            $amount = null;
        }

        $at = $decoded['at'] ?? null;
        $at = is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) ? $at : null;

        $note = $decoded['note'] ?? null;
        $note = is_string($note) ? trim($note) : null;
        if ($note === '') {
            $note = null;
        } elseif (mb_strlen($note) > 255) {
            $note = mb_substr($note, 0, 255);
        }

        $titles = $decoded['suggested_tag_titles'] ?? [];
        if (!is_array($titles)) {
            $titles = [];
        }
        $titles = array_values(array_filter(array_map(
            static function ($title) {
                if (!is_string($title)) {
                    return null;
                }
                $title = trim($title);

                return $title !== '' ? mb_substr($title, 0, 64) : null;
            },
            $titles,
        )));
        $titles = array_slice($titles, 0, 3);

        $category = $decoded['category'] ?? null;
        $category = is_string($category) ? trim($category) : null;
        if ($category === '') {
            $category = null;
        }

        $needsPrice = (bool) ($decoded['needs_price'] ?? false);
        if ($amount === null) {
            $needsPrice = true;
        }

        $confidence = $decoded['confidence'] ?? null;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null;

        return [
            'amount' => $amount,
            'at' => $at ?? $fallbackDate,
            'note' => $note,
            'suggested_tag_titles' => $titles,
            'category' => $category,
            'needs_price' => $needsPrice,
            'confidence' => $confidence,
        ];
    }
}
