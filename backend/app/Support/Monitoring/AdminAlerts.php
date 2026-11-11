<?php

namespace App\Support\Monitoring;

use App\Channels\Telegram\TelegramClient;
use App\Models\Identity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * System alerts for the operator in Telegram (failed jobs, queue worker down/recovered).
 * Recipients: TELEGRAM_ADMIN_CHAT_ID, else every admin with a linked Telegram.
 * Never throws: an alert must not break the job or command that raised it.
 */
class AdminAlerts
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {}

    /**
     * @return list<string>
     */
    public function chatIds(): array
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.telegram.admin_chat_id', '')),
        )));

        if ($configured !== []) {
            return $configured;
        }

        try {
            return Identity::query()
                ->where('provider', 'telegram')
                ->whereHas('user', fn ($q) => $q->where('is_admin', true)->whereNull('blocked_at'))
                ->pluck('external_id')
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Sends an HTML message to the admins. With a throttle key, the same alert goes out at most
     * once per $throttleSeconds (shared cache, so web, worker and scheduler agree).
     * Returns false when it was throttled or there is nobody to send it to.
     */
    public function send(string $html, ?string $throttleKey = null, int $throttleSeconds = 600): bool
    {
        try {
            if ($throttleKey !== null && !Cache::add('admin_alert:' . $throttleKey, true, $throttleSeconds)) {
                return false;
            }

            $chatIds = $this->chatIds();

            if ($chatIds === []) {
                Log::warning('admin alert dropped: no admin Telegram chat', ['text' => strip_tags($html)]);

                return false;
            }

            foreach ($chatIds as $chatId) {
                $this->client->sendMessage($chatId, $html, null, 'HTML');
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('admin alert failed: ' . $e->getMessage());

            return false;
        }
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
