<?php

namespace App\Channels\Telegram\Widgets;

use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
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
        private readonly TelegramSupport $support,
    ) {}

    public function html(string $kind): string
    {
        $path = resource_path('telegram/widgets/' . $kind . '.html');

        return (string) file_get_contents($path);
    }

    public function sendSummary(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_SUMMARY, $this->data->summary($user), allowQueries: false);
    }

    public function sendBudgets(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_BUDGETS, $this->data->budgets($user), allowQueries: true);
    }

    public function sendAdd(User $user, int|string $chatId): bool
    {
        $identity = $this->identityFor($user, $chatId);

        return $this->send($user, $chatId, TelegramWidgetData::KIND_ADD, $this->data->add($user, $identity), allowQueries: true);
    }

    public function sendRecent(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_RECENT, $this->data->recent($user), allowQueries: true);
    }

    public function sendTags(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_TAGS, $this->data->tags($user), allowQueries: true);
    }

    public function sendRecurring(User $user, int|string $chatId): bool
    {
        return $this->send($user, $chatId, TelegramWidgetData::KIND_RECURRING, $this->data->recurring($user), allowQueries: true);
    }

    public function sendNotifications(User $user, int|string $chatId): bool
    {
        $identity = $this->identityFor($user, $chatId);

        if (!$identity) {
            return false;
        }

        return $this->send($user, $chatId, TelegramWidgetData::KIND_NOTIFICATIONS, $this->data->notifications($identity), allowQueries: true);
    }

    /**
     * A one-off widget (editor, alert, digest): not remembered for sync, no menu upgrade.
     * Returns the message id, or null when Telegram refused it (callers fall back to text).
     *
     * @param null|array<string, mixed> $replyMarkup inline keyboard under the widget
     */
    public function sendOnce(int|string $chatId, string $kind, string $chatData, bool $allowQueries, ?array $replyMarkup = null): ?int
    {
        if (!$this->fitsLimits($kind)) {
            return null;
        }

        $result = $this->client->sendWidget($chatId, $this->html($kind), $chatData, null, $allowQueries, null, null, $replyMarkup);

        if (!($result['ok'] ?? false)) {
            Log::warning('Telegram sendWidget failed', [
                'kind' => $kind,
                'error_code' => $result['error_code'] ?? null,
                'description' => mb_substr((string) ($result['description'] ?? ''), 0, 240),
            ]);

            return null;
        }

        $id = $result['result']['message_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Budget alert / weekly digest (report.html) with buttons under it. Items are dropped from
     * the end to stay within 1 KB of chat_data ("+N more in /budgets").
     *
     * @param array{h: string, s?: string, k?: list<array{0: string, 1: string, 2?: string}>, l?: list<string>, g?: list<array{0: string, 1: list<array<int, mixed>>}>} $report
     * @param array<string, mixed> $keyboard
     */
    public function sendReport(int|string $chatId, array $report, array $keyboard): bool
    {
        $json = fn (array $p): string => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $more = 0;

        while (strlen($json($report)) > 1000 && ($report['g'] ?? []) !== []) {
            $last = count($report['g']) - 1;
            array_pop($report['g'][$last][1]);
            $more++;

            if ($report['g'][$last][1] === []) {
                array_pop($report['g']);
            }
            $report['more'] = $more;
        }

        return $this->sendOnce($chatId, TelegramWidgetData::KIND_REPORT, $json($report), false, $keyboard) !== null;
    }

    /**
     * The month summary pinned at the top of a private chat: its own ref ("summary_pin"), so a
     * later /month doesn't take over the refreshes.
     */
    public function sendPinnedSummary(User $user, Identity $identity): ?int
    {
        $chatId = $identity->external_id;
        $id = $this->sendOnce($chatId, TelegramWidgetData::KIND_SUMMARY, $this->data->summary($user)['chat_data'], false);

        if ($id === null) {
            return null;
        }

        $this->remember($identity, TelegramWidgetData::KIND_SUMMARY_PIN, $chatId, $id);
        $this->client->pinChatMessage($chatId, $id);

        return $id;
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
     * No caption: the widget carries its own title, and Telegram shows a caption
     * as an extra text line under the bubble.
     *
     * @param array{chat_data: string} $payload
     */
    private function send(
        User $user,
        int|string $chatId,
        string $kind,
        array $payload,
        bool $allowQueries,
        ?int $height = null,
    ): bool {
        if (!$this->fitsLimits($kind)) {
            return false;
        }

        $html = $this->html($kind);

        $identity = $this->identityFor($user, $chatId);
        $upgradeMenu = $identity && (int) ($identity->meta['menu_v'] ?? 1) < TelegramSupport::MENU_VERSION;
        $result = $this->client->sendWidget(
            $chatId,
            $html,
            $payload['chat_data'],
            null,
            $allowQueries,
            $height,
            null,
            $upgradeMenu ? $this->support->menuKeyboard() : null,
        );

        if ($upgradeMenu && !($result['ok'] ?? false)) {
            // Never lose the widget over the keyboard: retry without it.
            $upgradeMenu = false;
            $result = $this->client->sendWidget($chatId, $html, $payload['chat_data'], null, $allowQueries, $height);
        }

        if (!($result['ok'] ?? false)) {
            Log::warning('Telegram sendWidget failed', [
                'kind' => $kind,
                'error_code' => $result['error_code'] ?? null,
                'description' => mb_substr((string) ($result['description'] ?? ''), 0, 240),
            ]);

            return false;
        }

        $messageId = $result['result']['message_id'] ?? null;

        if ($identity && $upgradeMenu) {
            $identity->meta = [...($identity->meta ?? []), 'menu_v' => TelegramSupport::MENU_VERSION];
        }

        if (is_int($messageId) && $identity) {
            $this->remember($identity, $kind, $chatId, $messageId);
        } elseif ($identity && $upgradeMenu) {
            $identity->save();
        }

        return true;
    }

    private function fitsLimits(string $kind): bool
    {
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

        return true;
    }
}
