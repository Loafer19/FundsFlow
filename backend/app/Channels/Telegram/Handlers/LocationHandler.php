<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Transactions\UpdateTransactionAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramHistory;
use App\Channels\Telegram\TelegramSupport;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionLocation;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Cache;

/**
 * A location or venue sent in the chat. As a reply to a transaction card it becomes that
 * transaction's place; on its own the bot offers the latest transactions to attach it to
 * (or ✕ Ignore). The pending place waits 15 minutes.
 */
class LocationHandler
{
    private const PICK = 4;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly UpdateTransactionAction $updateTransaction,
        private readonly TelegramHistory $history,
    ) {}

    /**
     * @param array<string, mixed> $message
     */
    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'];
        $user = $this->support->resolveUser($chatId);

        if (!$user) {
            $this->client->sendMessage($chatId, "Your account isn't linked yet. Send /start to get started");

            return;
        }

        $place = TransactionLocation::fromTelegram($message);

        if ($place === null) {
            return;
        }

        $reply = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;
        $id = $reply ? $this->support->transactionIdFromReply($chatId, $reply) : null;
        $transaction = $id ? $this->support->findOwnTransaction($user, $id) : null;

        if ($transaction) {
            $this->attach($user, $chatId, $transaction, $place);

            return;
        }

        $recent = $user->transactions()->orderByDesc('at')->orderByDesc('id')->limit(self::PICK)->get();

        if ($recent->isEmpty()) {
            $this->client->sendMessage($chatId, 'Got the location, but there are no transactions to pin it to yet');

            return;
        }

        Cache::put(self::key($chatId), $place, now()->addMinutes(15));

        $rows = $recent->map(fn (Transaction $t): array => [[
            'text' => $this->buttonLabel($user, $t),
            'callback_data' => "loc:{$t->id}",
        ]])->all();
        $rows[] = [['text' => '✕ Ignore', 'callback_data' => 'loc:x']];

        $label = isset($place['title']) ? ' (' . $this->support->escapeHtml($place['title']) . ')' : '';
        $this->client->sendMessage(
            $chatId,
            "📍 Got a location{$label}. Pin it to a transaction?\n<i>Tip: reply to a transaction card with a location to pin it straight away.</i>",
            ['inline_keyboard' => $rows],
            TelegramSupport::PARSE_HTML,
        );
    }

    /**
     * loc:{id} pins the waiting place, loc:x drops it. Returns the toast text.
     */
    public function handleButton(User $user, int|string $chatId, ?int $messageId, string $choice): string
    {
        $place = Cache::pull(self::key($chatId));

        if ($choice === 'x' || !is_array($place)) {
            if ($messageId) {
                $this->client->editMessageText($chatId, $messageId, is_array($place) || $choice === 'x' ? '📍 Location ignored' : '📍 This location expired. Send it again');
            }

            return $choice === 'x' ? 'Location ignored!' : 'Location expired';
        }

        $transaction = $this->support->findOwnTransaction($user, (int) $choice);

        if (!$transaction) {
            return 'Transaction not found';
        }

        if ($messageId) {
            $this->client->deleteMessage($chatId, $messageId);
        }

        $this->attach($user, $chatId, $transaction, $place);

        return 'Place added!';
    }

    /**
     * @param array{lat: float, lng: float, title?: string, address?: string} $place
     */
    private function attach(User $user, int|string $chatId, Transaction $transaction, array $place): void
    {
        $transaction = $this->updateTransaction->execute($user, $transaction, ['location' => $place], requireAllFields: false);
        $this->history->outcome($chatId, $user, 'Place pinned' . (isset($place['title']) ? ' (' . $place['title'] . ')' : '') . ': ' . TelegramHistory::transactionLine($user, $transaction));
        $this->support->sendSavedTransaction($chatId, $user, $transaction, '📍 Place added', remember: false);
    }

    private function buttonLabel(User $user, Transaction $transaction): string
    {
        $note = trim((string) $transaction->note);

        return mb_strimwidth(
            $this->support->signedMoney($user, (float) $transaction->amount) . ($note !== '' ? ' · ' . $note : '') . ' · ' . UserFormatter::formatDate($user, $transaction->at),
            0,
            48,
            '…',
        );
    }

    private static function key(int|string $chatId): string
    {
        return "telegram_location:{$chatId}";
    }
}
