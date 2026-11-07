<?php

namespace App\Actions\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;

/**
 * Adds a message to a support thread. A user reply reopens a closed request and alerts the
 * admins; an admin reply moves it to the chosen status and reaches the user in Telegram.
 */
class ReplyToSupportTicketAction
{
    public function __construct(
        private readonly SupportNotifier $notifier,
    ) {}

    public function fromUser(SupportTicket $ticket, string $body): SupportTicketMessage
    {
        $body = trim(mb_substr($body, 0, CreateSupportTicketAction::BODY_MAX));

        $message = $ticket->messages()->create([
            'author' => SupportTicketMessage::AUTHOR_USER,
            'body' => $body,
        ]);

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            $ticket->status = SupportTicket::STATUS_NEW;
        }
        // touch() also saves the status change.
        $ticket->touch();

        $this->notifier->userReplied($ticket, $body);

        return $message;
    }

    /**
     * @return array{message: SupportTicketMessage, telegram: bool}
     */
    public function fromAdmin(SupportTicket $ticket, User $admin, string $body, string $status = SupportTicket::STATUS_IN_PROGRESS): array
    {
        $body = trim(mb_substr($body, 0, CreateSupportTicketAction::BODY_MAX));

        $message = $ticket->messages()->create([
            'author' => SupportTicketMessage::AUTHOR_ADMIN,
            'admin_id' => $admin->id,
            'body' => $body,
        ]);

        $ticket->status = array_key_exists($status, SupportTicket::STATUSES) ? $status : SupportTicket::STATUS_IN_PROGRESS;
        $ticket->touch();

        return ['message' => $message, 'telegram' => $this->notifier->adminReplied($ticket, $body)];
    }
}
