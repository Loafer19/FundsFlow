<?php

namespace App\Actions\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One entry point for new support requests: web form, /support in the bot, and the assistant.
 */
class CreateSupportTicketAction
{
    public const SUBJECT_MAX = 120;

    public const BODY_MAX = 5000;

    public function __construct(
        private readonly SupportNotifier $notifier,
    ) {}

    /**
     * @throws SupportRateLimitException
     */
    public function execute(
        User $user,
        string $body,
        ?string $subject = null,
        string $source = SupportTicket::SOURCE_WEB,
        ?string $appVersion = null,
        ?string $userAgent = null,
    ): SupportTicket {
        $this->ensureAllowed($user);

        $body = trim(mb_substr($body, 0, self::BODY_MAX));
        $subject = trim((string) $subject);
        if ($subject === '') {
            $subject = self::subjectFrom($body);
        }

        $ticket = $user->supportTickets()->create([
            'subject' => mb_substr($subject, 0, self::SUBJECT_MAX),
            'body' => $body,
            'status' => SupportTicket::STATUS_NEW,
            'source' => $source === SupportTicket::SOURCE_TELEGRAM ? SupportTicket::SOURCE_TELEGRAM : SupportTicket::SOURCE_WEB,
            'app_version' => $appVersion !== null ? mb_substr($appVersion, 0, 32) : null,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
        ]);

        $this->notifier->newTicket($ticket);

        return $ticket;
    }

    /**
     * @throws SupportRateLimitException
     */
    public function ensureAllowed(User $user): void
    {
        $max = max(1, (int) config('support.max_per_hour', 5));

        if ($user->supportTickets()->where('created_at', '>=', now()->subHour())->count() >= $max) {
            throw new SupportRateLimitException('Too many requests in the last hour. Please try again later or reply to an existing request.');
        }
    }

    /** First line of the message, shortened: used when the bot or the assistant sends no subject. */
    public static function subjectFrom(string $body): string
    {
        $line = trim(Str::before(trim($body), "\n"));

        return $line === '' ? 'Support request' : Str::limit($line, 60, '…');
    }
}
