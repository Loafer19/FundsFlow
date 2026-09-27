<?php

namespace App\Actions\Telegram;

use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Speech-to-text for Telegram voice/audio via xAI POST /v1/stt (reuses XAI_API_KEY).
 * Counts toward the shared TelegramAiQuota (same daily budget as receipt vision + NL).
 */
class TranscribeTelegramAudioAction
{
    public function __construct(
        private readonly TelegramAiQuota $quota,
    ) {}

    public function isConfigured(): bool
    {
        return (string) config('services.xai.api_key') !== '';
    }

    public function hasQuota(User $user): bool
    {
        return $this->quota->hasQuota($user);
    }

    /**
     * Transcribe audio bytes. Does not consume quota — caller consumes on non-empty text.
     *
     * @return string|null Transcript text, empty string when STT returned no speech, null on hard failure.
     */
    public function execute(User $user, string $audioBytes, string $filename, string $mime): ?string
    {
        $apiKey = (string) config('services.xai.api_key');
        $baseUrl = rtrim((string) config('services.xai.base_url', 'https://api.x.ai/v1'), '/');
        $model = (string) config('services.xai.stt_model', 'grok-voice-transcribe-2.0');

        if ($apiKey === '') {
            Log::warning('TranscribeTelegramAudioAction: XAI_API_KEY is empty');

            return null;
        }

        if ($audioBytes === '') {
            return '';
        }

        // Prefer a sensible filename extension for OGG/Opus Telegram voice notes.
        $safeName = $filename !== '' ? $filename : 'voice.ogg';
        $safeMime = $mime !== '' ? $mime : 'audio/ogg';

        try {
            // Option fields must precede `file` in the multipart body (xAI STT requirement).
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->acceptJson()
                ->asMultipart()
                ->post($baseUrl . '/stt', [
                    [
                        'name' => 'model',
                        'contents' => $model,
                    ],
                    [
                        'name' => 'format',
                        'contents' => 'true',
                    ],
                    [
                        'name' => 'language',
                        'contents' => 'en',
                    ],
                    [
                        'name' => 'keyterm',
                        'contents' => 'FundsFlow',
                    ],
                    [
                        'name' => 'keyterm',
                        'contents' => 'groceries',
                    ],
                    [
                        'name' => 'file',
                        'contents' => $audioBytes,
                        'filename' => $safeName,
                        'headers' => [
                            'Content-Type' => $safeMime,
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('TranscribeTelegramAudioAction: xAI STT HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $text = data_get($response->json(), 'text');

            if (!is_string($text)) {
                return '';
            }

            return trim($text);
        } catch (Throwable $e) {
            Log::warning('TranscribeTelegramAudioAction: exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function consumeQuota(User $user): void
    {
        $this->quota->consume($user);
    }

    public function dailyLimit(): int
    {
        return $this->quota->dailyLimit();
    }

    public function quota(): TelegramAiQuota
    {
        return $this->quota;
    }
}
