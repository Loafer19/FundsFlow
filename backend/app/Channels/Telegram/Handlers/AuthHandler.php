<?php

namespace App\Channels\Telegram\Handlers;

use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AuthHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
    ) {}

    /**
     * @param array<string, mixed> $message
     */
    public function handleStart(array $message): void
    {
        $chatId = $message['chat']['id'];
        $payload = trim(substr(trim($message['text']), strlen('/start')));

        if ($this->support->resolveUser($chatId)) {
            $this->support->sendWelcome($chatId, "You're already linked to your FundsFlow account");

            return;
        }

        $meta = [
            'username' => $message['from']['username'] ?? null,
            'first_name' => $message['from']['first_name'] ?? null,
        ];

        if ($payload !== '') {
            $this->linkToExistingAccount($chatId, $payload, $meta);

            return;
        }

        $this->registerNewAccount($chatId, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function linkToExistingAccount(int|string $chatId, string $code, array $meta): void
    {
        $userId = Cache::pull("telegram_deeplink:{$code}");

        if (!$userId) {
            $this->client->sendMessage(
                $chatId,
                "This link has expired. Get a new one from FundsFlow → Settings → Accounts, "
                    . 'or send /start with no link to create a new account.',
            );

            return;
        }

        $user = User::find($userId);

        if (!$user) {
            $this->client->sendMessage($chatId, 'That account no longer exists. Send /start to create a new one');

            return;
        }

        $user->identities()->create([
            'provider' => 'telegram',
            'external_id' => (string) $chatId,
            'meta' => $meta,
        ]);

        $this->support->sendWelcome($chatId, '✅ Linked to your FundsFlow account');
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function registerNewAccount(int|string $chatId, array $meta): void
    {
        $user = User::create([
            'name' => $meta['first_name'] ?: 'Telegram User',
            'email' => "telegram-{$chatId}@fundsflow.invalid",
            'password' => str()->random(32),
        ]);

        $user->identities()->create([
            'provider' => 'telegram',
            'external_id' => (string) $chatId,
            'meta' => $meta,
        ]);

        $this->support->sendWelcome($chatId, '✅ Account created');
    }

    public function sendWebsiteLoginCode(User $user, int|string $chatId): void
    {
        $code = $this->generateWebsiteLoginCode();

        Cache::put("telegram_login:{$code}", $user->id, now()->addMinutes(10));

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $loginUrl = $frontend.'/?telegram_code='.rawurlencode($code);

        $this->client->sendMessage(
            $chatId,
            "Web login code:\n<code>{$code}</code>\n\nOpen FundsFlow to sign in automatically, or copy the code. Valid 10 minutes.",
            [
                'inline_keyboard' => [
                    [['text' => 'Open FundsFlow', 'url' => $loginUrl]],
                    [['text' => 'Copy code', 'copy_text' => ['text' => $code]]],
                ],
            ],
            'HTML',
        );
    }

    public function generateWebsiteLoginCode(): string
    {
        // No 0/O/1/I — easier to type; 8 chars from 32 symbols ≈ 1e12 variants.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length = 8;

        do {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Cache::has("telegram_login:{$code}"));

        return $code;
    }

    public function handleUnlink(int|string $chatId): void
    {
        $identity = $this->support->resolveIdentity($chatId);

        if (!$identity) {
            $this->client->sendMessage($chatId, "This chat isn't linked to a FundsFlow account");

            return;
        }

        $identity->delete();

        $this->client->sendMessage(
            $chatId,
            '🔓 Unlinked. Your FundsFlow account remains on the website, but this chat is no longer connected',
        );
    }

    public function handleMute(int|string $chatId, bool $muted): void
    {
        $identity = $this->support->resolveIdentity($chatId);

        $identity->update(['meta' => [...$identity->meta, 'muted' => $muted]]);

        $this->client->sendMessage($chatId, $muted
            ? '🔕 Notifications muted. Use /unmute to turn budget alerts, weekly digest, and recurring auto-add notifications back on'
            : '🔔 Notifications unmuted — budget alerts, weekly digest, and recurring auto-add notifications are on');
    }
}
