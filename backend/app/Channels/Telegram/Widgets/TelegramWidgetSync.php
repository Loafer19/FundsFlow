<?php

namespace App\Channels\Telegram\Widgets;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Refresh open summary/budgets/add widgets after a transaction changes.
 * Coalesces many writes in one request into a single edit per widget.
 */
class TelegramWidgetSync
{
    /** @var array<int, User> */
    private static array $pending = [];

    private static bool $registered = false;

    public function __construct(
        private readonly TelegramWidgetSender $sender,
        private readonly TelegramWidgetData $data,
    ) {}

    public function schedule(User $user): void
    {
        self::$pending[(int) $user->id] = $user;

        if (self::$registered) {
            return;
        }

        self::$registered = true;
        // Normal HTTP/console: flush once the request ends. Jobs that already run
        // during terminate() (Telegram afterResponse) should call flush() themselves.
        app()->terminating(fn () => $this->flush());
    }

    public function flush(): void
    {
        $users = self::$pending;
        self::$pending = [];
        self::$registered = false;

        foreach ($users as $user) {
            $this->refresh($user);
        }
    }

    public function refresh(User $user): void
    {
        $this->refreshKind($user, TelegramWidgetData::KIND_SUMMARY, fn () => $this->data->summary($user)['chat_data']);
        $this->refreshKind($user, TelegramWidgetData::KIND_BUDGETS, fn () => $this->data->budgets($user)['chat_data']);
        $this->refreshKind($user, TelegramWidgetData::KIND_ADD, function () use ($user): string {
            $refs = $this->sender->stored($user, TelegramWidgetData::KIND_ADD);
            $identity = $refs[0]['identity'] ?? null;

            return $this->data->add($user, $identity)['chat_data'];
        });
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

        $lock = 'tg_widget_edit:' . $user->id . ':' . $kind;

        if (!Cache::add($lock, 1, now()->addSeconds(2))) {
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
