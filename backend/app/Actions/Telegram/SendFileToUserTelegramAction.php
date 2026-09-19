<?php

namespace App\Actions\Telegram;

use App\Channels\Telegram\TelegramClient;
use App\Models\User;
use RuntimeException;

class SendFileToUserTelegramAction
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        string $contents,
        string $filename,
        string $mime,
        ?string $caption = null,
    ): array {
        $identity = $user->identities()
            ->where('provider', 'telegram')
            ->first();

        if ($identity === null) {
            abort(422, 'Link Telegram in Settings first.');
        }

        $chatId = $identity->external_id;
        $safeName = $filename !== '' ? $filename : 'file';

        try {
            if ($this->shouldSendAsPhoto($mime)) {
                return $this->client->sendPhoto($chatId, $contents, $safeName, $caption);
            }

            return $this->client->sendDocument($chatId, $contents, $safeName, $caption);
        } catch (RuntimeException $e) {
            abort(502, $e->getMessage());
        }
    }

    private function shouldSendAsPhoto(string $mime): bool
    {
        return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
    }
}
