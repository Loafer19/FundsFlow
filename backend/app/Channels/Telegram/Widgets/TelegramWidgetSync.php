<?php

namespace App\Channels\Telegram\Widgets;

use App\Channels\Telegram\PinnedMonthSummary;
use App\Models\Identity;
use App\Models\User;
use Throwable;

/**
 * Refresh open summary/budgets/add/recent widgets after a transaction changes.
 */
class TelegramWidgetSync
{
    public function __construct(
        private readonly TelegramWidgetSender $sender,
        private readonly TelegramWidgetData $data,
    ) {}

    public function refresh(User $user): void
    {
        $this->refreshKind($user, TelegramWidgetData::KIND_SUMMARY, fn () => $this->data->summary($user)['chat_data']);
        $this->refreshPinned($user);
        $this->refreshKind($user, TelegramWidgetData::KIND_BUDGETS, fn () => $this->data->budgets($user)['chat_data']);
        $this->refreshKind($user, TelegramWidgetData::KIND_ADD, function () use ($user): string {
            $refs = $this->sender->stored($user, TelegramWidgetData::KIND_ADD);
            $identity = $refs[0]['identity'] ?? null;

            return $this->data->add($user, $identity)['chat_data'];
        });
        $this->refreshKind($user, TelegramWidgetData::KIND_RECENT, fn () => $this->data->recent($user)['chat_data']);
        $this->refreshTags($user);
        $this->refreshRecurring($user);
    }

    /**
     * The pinned month summary, only while it still shows the current month (rollover re-pins).
     */
    public function refreshPinned(User $user): void
    {
        $refs = array_values(array_filter(
            $this->sender->stored($user, TelegramWidgetData::KIND_SUMMARY_PIN),
            fn (array $ref): bool => app(PinnedMonthSummary::class)->isCurrent($ref['identity']->setRelation('user', $user)),
        ));

        if ($refs === []) {
            return;
        }

        $payload = $this->data->summary($user)['chat_data'];

        foreach ($refs as $ref) {
            $this->editRef($ref, TelegramWidgetData::KIND_SUMMARY_PIN, $payload);
        }
    }

    public function refreshTags(User $user): void
    {
        $this->refreshKind($user, TelegramWidgetData::KIND_TAGS, fn () => $this->data->tags($user)['chat_data']);
    }

    /**
     * Also after a rule is paused or resumed from the widget, so a reopened chat shows the new state.
     */
    public function refreshRecurring(User $user): void
    {
        $this->refreshKind($user, TelegramWidgetData::KIND_RECURRING, fn () => $this->data->recurring($user)['chat_data']);
    }

    /**
     * Notifications are muted per chat, so each stored widget gets its own identity's state.
     */
    public function refreshNotifications(User $user): void
    {
        foreach ($this->sender->stored($user, TelegramWidgetData::KIND_NOTIFICATIONS) as $ref) {
            $identity = $ref['identity']->fresh() ?? $ref['identity'];
            $this->editRef($ref, TelegramWidgetData::KIND_NOTIFICATIONS, $this->data->notifications($identity)['chat_data']);
        }
    }

    /**
     * @param callable(): string $chatData
     */
    private function refreshKind(User $user, string $kind, callable $chatData): void
    {
        $refs = $this->sender->stored($user, $kind);

        if ($refs === []) {
            return;
        }

        try {
            $payload = $chatData();
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ($refs as $ref) {
            $this->editRef($ref, $kind, $payload);
        }
    }

    /**
     * @param array{identity: Identity, chat_id: int|string, message_id: int} $ref
     */
    private function editRef(array $ref, string $kind, string $payload): void
    {
        try {
            $result = $this->sender->editChatData($ref['chat_id'], $ref['message_id'], $payload);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($result['ok'] ?? false) {
            return;
        }

        $description = (string) ($result['description'] ?? '');

        if (str_contains($description, 'message is not modified')) {
            return;
        }

        if (str_contains($description, 'message to edit not found')
            || str_contains($description, 'MESSAGE_ID_INVALID')
            || str_contains($description, 'message not found')) {
            $this->sender->forget($ref['identity'], $kind);
        }
    }
}
