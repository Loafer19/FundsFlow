<?php

namespace App\Channels\Telegram;

use App\Channels\Telegram\Widgets\TelegramWidgetData;
use App\Channels\Telegram\Widgets\TelegramWidgetSender;
use App\Models\Identity;
use App\Models\User;
use Throwable;

/**
 * "Pinned month summary" switch in /notifications: the /month widget pinned at the top of the
 * private chat, refreshed by the usual widget sync. On the 1st a fresh one is sent and pinned,
 * and last month's message is unpinned and left as it was (a record of that month).
 *
 * Identity meta: pin (switch), pin_period (Y-m the pinned widget shows), widgets.summary_pin.
 */
class PinnedMonthSummary
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramWidgetSender $sender,
    ) {}

    public function enable(User $user, Identity $identity): void
    {
        $this->setMeta($identity, ['pin' => true]);

        if ($this->ref($identity) === null) {
            $this->pinFresh($user, $identity);
        }
    }

    public function disable(Identity $identity): void
    {
        $this->setMeta($identity, ['pin' => false]);
        $this->unpinCurrent($identity);
    }

    /**
     * Hourly: a chat whose pinned widget shows an older month gets this month's.
     *
     * @return int chats re-pinned
     */
    public function rollover(): int
    {
        $count = 0;

        Identity::query()
            ->where('provider', 'telegram')
            ->with('user')
            ->chunkById(100, function ($identities) use (&$count): void {
                foreach ($identities as $identity) {
                    if (!($identity->meta['pin'] ?? false) || !$identity->user || (int) $identity->external_id <= 0) {
                        continue;
                    }

                    if (($identity->meta['pin_period'] ?? null) === $this->period($identity->user)) {
                        continue;
                    }

                    try {
                        $this->unpinCurrent($identity);

                        if ($this->pinFresh($identity->user, $identity)) {
                            $count++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    /**
     * The sync must not turn last month's pinned message into this month's before rollover()
     * re-pins: only refresh while it still shows the current month.
     */
    public function isCurrent(Identity $identity): bool
    {
        return $identity->user !== null && ($identity->meta['pin_period'] ?? null) === $this->period($identity->user);
    }

    private function pinFresh(User $user, Identity $identity): bool
    {
        if ($this->sender->sendPinnedSummary($user, $identity) === null) {
            return false;
        }

        $this->setMeta($identity->refresh(), ['pin_period' => $this->period($user)]);

        return true;
    }

    private function unpinCurrent(Identity $identity): void
    {
        $ref = $this->ref($identity);

        if ($ref === null) {
            return;
        }

        $this->client->unpinChatMessage($ref['chat_id'], $ref['message_id']);
        $this->sender->forget($identity, TelegramWidgetData::KIND_SUMMARY_PIN);
    }

    /**
     * @return null|array{chat_id: int|string, message_id: int}
     */
    private function ref(Identity $identity): ?array
    {
        $ref = $identity->meta['widgets'][TelegramWidgetData::KIND_SUMMARY_PIN] ?? null;

        return is_array($ref) && isset($ref['message_id'])
            ? ['chat_id' => $ref['chat_id'] ?? $identity->external_id, 'message_id' => (int) $ref['message_id']]
            : null;
    }

    private function period(User $user): string
    {
        return $user->nowInTimezone()->format('Y-m');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function setMeta(Identity $identity, array $values): void
    {
        $identity->meta = [...($identity->meta ?? []), ...$values];
        $identity->save();
    }
}
