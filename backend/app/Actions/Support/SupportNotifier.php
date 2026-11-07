<?php

namespace App\Actions\Support;

use App\Channels\Telegram\TelegramClient;
use App\Models\Identity;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram alerts for support requests. Admin inbox = every admin (users.is_admin, not blocked)
 * with a linked Telegram, plus SUPPORT_ADMIN_TELEGRAM_CHAT_ID. Never throws: a failed alert
 * must not lose the request itself.
 */
class SupportNotifier
{
    private const PREVIEW_MAX = 1500;

    public function __construct(
        private readonly TelegramClient $client,
    ) {}

    public function newTicket(SupportTicket $ticket): void
    {
        $this->toAdmins($ticket, '🆘 New support request', $ticket->body);
    }

    public function userReplied(SupportTicket $ticket, string $body): void
    {
        $this->toAdmins($ticket, '💬 New reply on a support request', $body);
    }

    /**
     * The admin's reply in the user's Telegram (when linked). False when it couldn't be sent.
     */
    public function adminReplied(SupportTicket $ticket, string $body): bool
    {
        $chatId = $this->userChatId($ticket->user);
        if ($chatId === null) {
            return false;
        }

        $text = '💬 <b>Reply from FundsFlow support</b>' . "\n"
            . 'Re: ' . $this->escape($ticket->subject) . "\n\n"
            . $this->escape($this->cut($body)) . "\n\n"
            . 'Answer with <code>/support #' . $ticket->id . ' your message</code>, or open Settings → Support in the web app.';

        return $this->send($chatId, $text);
    }

    /**
     * @return list<string>
     */
    public function adminChatIds(): array
    {
        $fromAdmins = Identity::query()
            ->where('provider', 'telegram')
            ->whereHas('user', fn ($q) => $q->where('is_admin', true)->whereNull('blocked_at'))
            ->pluck('external_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return array_values(array_unique([...$fromAdmins, ...config('support.admin_telegram_chat_ids', [])]));
    }

    private function toAdmins(SupportTicket $ticket, string $headline, string $body): void
    {
        $ticket->loadMissing('user');
        $user = $ticket->user;
        $who = $user ? ($user->email ?: $user->name ?: ('user #' . $user->id)) : 'deleted user';
        $meta = array_filter([
            $ticket->source,
            $ticket->app_version ? 'v' . $ticket->app_version : null,
        ]);

        $text = '<b>' . $this->escape($headline) . '</b> #' . $ticket->id . "\n"
            . $this->escape($who) . ($meta ? ' · ' . $this->escape(implode(' · ', $meta)) : '') . "\n"
            . '<b>' . $this->escape($ticket->subject) . "</b>\n\n"
            . $this->escape($this->cut($body)) . "\n\n"
            . $this->escape(rtrim((string) config('app.url'), '/') . '/admin/support-tickets/' . $ticket->id);

        foreach ($this->adminChatIds() as $chatId) {
            $this->send($chatId, $text);
        }
    }

    private function userChatId(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        $identity = $user->identities()->where('provider', 'telegram')->first();

        return $identity ? (string) $identity->external_id : null;
    }

    private function send(string $chatId, string $text): bool
    {
        if (blank(config('services.telegram.bot_token'))) {
            return false;
        }

        try {
            return (bool) ($this->client->sendMessageResult($chatId, $text, null, 'HTML')['ok'] ?? false);
        } catch (Throwable $e) {
            Log::warning('Support Telegram alert failed', ['message' => $e->getMessage()]);

            return false;
        }
    }

    private function cut(string $text): string
    {
        return mb_strlen($text) > self::PREVIEW_MAX ? mb_substr($text, 0, self::PREVIEW_MAX - 1) . '…' : $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
