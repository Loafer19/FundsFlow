<?php

namespace App\Channels\Telegram\Widgets;

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
        $this->refreshKind($user, TelegramWidgetData::KIND_BUDGETS, fn () => $this->data->budgets($user)['chat_data']);
        $this->refreshKind($user, TelegramWidgetData::KIND_ADD, function () use ($user): string {
            $refs = $this->sender->stored($user, TelegramWidgetData::KIND_ADD);
            $identity = $refs[0]['identity'] ?? null;

            return $this->data->add($user, $identity)['chat_data'];
        });
        $this->refreshKind($user, TelegramWidgetData::KIND_RECENT, fn () => $this->data->recent($user)['chat_data']);
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
            try {
                $result = $this->sender->editChatData($ref['chat_id'], $ref['message_id'], $payload);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($result['ok'] ?? false) {
                continue;
            }

            $description = (string) ($result['description'] ?? '');

            if (str_contains($description, 'message is not modified')) {
                continue;
            }

            if (str_contains($description, 'message to edit not found')
                || str_contains($description, 'MESSAGE_ID_INVALID')
                || str_contains($description, 'message not found')) {
                $this->sender->forget($ref['identity'], $kind);
            }
        }
    }
}
