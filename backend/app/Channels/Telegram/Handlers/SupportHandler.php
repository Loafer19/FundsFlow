<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Support\CreateSupportTicketAction;
use App\Actions\Support\ReplyToSupportTicketAction;
use App\Actions\Support\SupportRateLimitException;
use App\Channels\Telegram\TelegramClient;
use App\Models\SupportTicket;
use App\Models\User;

/**
 * /support <text> opens a support request; /support #12 <text> adds to request #12.
 * Replies from support arrive in this chat (App\Actions\Support\SupportNotifier).
 */
class SupportHandler
{
    public const FAQ_URL = 'https://fundsflow.fun/docs/help/faq/';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly CreateSupportTicketAction $create,
        private readonly ReplyToSupportTicketAction $reply,
    ) {}

    public static function matches(string $text): bool
    {
        return (bool) preg_match('/^\/support(@\w+)?(\s|$)/u', $text);
    }

    public function handle(User $user, int|string $chatId, string $text): void
    {
        $message = trim((string) preg_replace('/^\/support(@\w+)?/u', '', $text));

        if ($message === '') {
            $this->client->sendMessage(
                $chatId,
                "Describe the problem after the command, for example:\n"
                . "/support The PDF report doesn't open\n\n"
                . "To add to an earlier request: /support #12 your message\n"
                . 'Common questions: ' . self::FAQ_URL,
            );

            return;
        }

        if (preg_match('/^#(\d+)\s+(.+)$/su', $message, $m)) {
            $ticket = $user->supportTickets()->find((int) $m[1]);
            if (!$ticket) {
                $this->client->sendMessage($chatId, "Request #{$m[1]} wasn't found. Send /support with your message to open a new one.");

                return;
            }
            $this->reply->fromUser($ticket, $m[2]);
            $this->client->sendMessage($chatId, "✅ Added to request #{$ticket->id}. We'll reply here.");

            return;
        }

        try {
            $ticket = $this->create->execute($user, $message, null, SupportTicket::SOURCE_TELEGRAM, null, 'Telegram bot');
        } catch (SupportRateLimitException $e) {
            $this->client->sendMessage($chatId, $e->getMessage());

            return;
        }

        $this->client->sendMessage(
            $chatId,
            "✅ Request #{$ticket->id} sent. We'll reply here in this chat.\n"
            . 'You can also follow it in the web app: Settings → Support.',
        );
    }
}
