<?php

namespace App\Actions\Monobank;

use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\MonobankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;

class NotifyMonobankImportAction
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
    ) {}

    public function execute(User $user, Transaction $transaction, MonobankConnection $connection): void
    {
        if (!$connection->notify) {
            return;
        }

        $user->loadMissing('identities');
        $identity = $user->identities->firstWhere('provider', 'telegram');

        if (!$identity || ($identity->meta['muted'] ?? false)) {
            return;
        }

        $transaction->loadMissing('tags');
        $merchant = $this->support->escapeHtml($this->truncate((string) ($transaction->note ?: 'Monobank')));
        $money = $this->support->escapeHtml(UserFormatter::formatMoney($user, $transaction->amount));
        $tag = $transaction->tags->first();
        $tagLabel = $tag
            ? $this->support->escapeHtml(trim($tag->emoji . ' ' . $tag->title))
            : 'needs a tag';

        $this->client->sendMessage(
            $identity->external_id,
            "<b>{$merchant}</b> {$money} → {$tagLabel}",
            $this->support->postSaveKeyboard($transaction),
            TelegramSupport::PARSE_HTML,
        );
    }

    private function truncate(string $text): string
    {
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
    }
}
