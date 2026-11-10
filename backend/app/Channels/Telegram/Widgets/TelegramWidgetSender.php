<?php

namespace App\Channels\Telegram\Widgets;

use App\Channels\Telegram\TelegramClient;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Loads widget HTML, sends it, and remembers the message so later edits can refresh chat_data.
 */
class TelegramWidgetSender
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramWidgetData $data,
    ) {}

    public function html(string $kind): string
    {
        $path = resource_path('telegram/widgets/' . $kind . '.html');

        return (string) file_get_contents($path);
    }

    public function sendSummary(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_SUMMARY, $this->data->summary($user), allowQueries: false, height: 280);
    }

    public function sendBudgets(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_BUDGETS, $this->data->budgets($user), allowQueries: true, height: 360);
    }

    public function sendAdd(User $user, int|string $chatId): bool
    {
        $identity = $this->identityFor($user, $chatId);

        return $this->send($user, $chatId, TelegramWidgetData::KIND_ADD, $this->data->add($user, $identity), allowQueries: true, height: 440);
    }

    /**
     * @return list<array{identity: Identity, chat_id: int|string, message_id: int}>
     */
    public function stored(User $user, string $kind): array
    {
        $out = [];

        foreach ($user->identities()->where('provider', 'telegram')->get() as $identity) {
            $ref = $identity->meta['widgets'][$kind] ?? null;

            if (!is_array($ref) || !isset($ref['message_id'])) {
                continue;
            }
            $out[] = [
                'identity' => $identity,
                'chat_id' => $ref['chat_id'] ?? $identity->external_id,
                'message_id' => (int) $ref['message_id'],
            ];
        }

        return $out;
    }

    public function remember(Identity $identity, string $kind, int|string $chatId, int $messageId): void
    {
        $meta = $identity->meta ?? [];
        $meta['widgets'][$kind] = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];
        $identity->meta = $meta;
        $identity->save();
    }

    public function forget(Identity $identity, string $kind): void
    {
        $meta = $identity->meta ?? [];
        unset($meta['widgets'][$kind]);
        $identity->meta = $meta;
        $identity->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function editChatData(int|string $chatId, int $messageId, string $chatData): array
    {
        return $this->client->editMessageWidgetData($chatId, $messageId, $chatData);
    }

    public function identityFor(User $user, int|string $chatId): ?Identity
    {
        return Identity::query()
            ->where('provider', 'telegram')
            ->where('external_id', (string) $chatId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * @param array{chat_data: string, caption: string} $payload
     */
    private function send(
        User $user,
        int|string $chatId,
        string $kind,
        array $payload,
        bool $allowQueries,
        ?int $height,
    ): bool {
        $html = $this->html($kind);
        $raw = strlen($html);
        $compressed = strlen(gzcompress($html, 9) ?: '');

        if ($raw > 32768 || $compressed > 4096) {
            Log::error('Telegram widget HTML exceeds Bot API limits', [
                'kind' => $kind,
                'raw' => $raw,
                'compressed' => $compressed,
            ]);

            return false;
        }

        $result = $this->client->sendWidget(
            $chatId,
            $html,
            $payload['chat_data'],
            $payload['caption'],
            $allowQueries,
            $height,
            null,
        );

        if (!($result['ok'] ?? false)) {
            Log::warning('Telegram sendWidget failed', [
                'kind' => $kind,
                'error_code' => $result['error_code'] ?? null,
                'description' => mb_substr((string) ($result['description'] ?? ''), 0, 240),
            ]);

            return false;
        }

        $messageId = $result['result']['message_id'] ?? null;
        $identity = $this->identityFor($user, $chatId);

        if (is_int($messageId) && $identity) {
            $this->remember($identity, $kind, $chatId, $messageId);
        }

        return true;
    }
}
