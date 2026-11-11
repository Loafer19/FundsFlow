<?php

namespace App\Channels\Telegram;

use App\Actions\RecurringTransactions\UpdateRecurringTransactionAction;
use App\Channels\Telegram\Widgets\TelegramWidgetSync;
use App\Enums\RecurringFrequency;
use App\Models\Identity;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * A day before a recurring payment is added, one message per rule with ⏭ Skip and ⏸ Pause.
 * Sent from the user's local 18:00 on (hourly scheduler run), once per rule and date.
 * Daily rules are left out (a reminder every day is noise). Off with the switch in
 * /notifications (meta remind_off) or when the chat is muted.
 */
class RecurringReminders
{
    public const LOCAL_HOUR = 18;

    private const MAX_PER_USER = 5;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly UpdateRecurringTransactionAction $updateRecurring,
        private readonly TelegramWidgetSync $sync,
        private readonly TelegramHistory $history,
    ) {}

    /**
     * @return int reminders sent
     */
    public function sendDue(): int
    {
        $sent = 0;
        $latest = now()->addDays(2)->toDateString();

        $rules = RecurringTransaction::query()
            ->where('active', true)
            ->where('frequency', '!=', RecurringFrequency::Daily->value)
            ->where('next_run_at', '<=', $latest)
            ->with(['user.identities', 'tags'])
            ->orderBy('next_run_at')
            ->get()
            ->groupBy('user_id');

        foreach ($rules as $userRules) {
            $user = $userRules->first()->user;
            $identity = $user?->identities->firstWhere('provider', 'telegram');

            if (!$user || !$identity || !$this->enabledFor($identity)) {
                continue;
            }

            $now = $user->nowInTimezone();

            if ($now->hour < self::LOCAL_HOUR) {
                continue;
            }

            $tomorrow = $now->copy()->addDay()->toDateString();
            $count = 0;

            foreach ($userRules as $rule) {
                $date = $rule->next_run_at->toDateString();

                if ($date !== $tomorrow || ($rule->ends_at && $date > $rule->ends_at->toDateString())) {
                    continue;
                }

                if ($count >= self::MAX_PER_USER || !Cache::add($this->sentKey($rule, $date), true, now()->addDays(3))) {
                    continue;
                }

                try {
                    $this->client->sendMessage(
                        $identity->external_id,
                        $this->text($user, $rule, '🔔 Tomorrow'),
                        $this->keyboard($rule),
                        TelegramSupport::PARSE_HTML,
                    );
                    $count++;
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $sent;
    }

    public function enabledFor(Identity $identity): bool
    {
        return !($identity->meta['muted'] ?? false) && !($identity->meta['remind_off'] ?? false);
    }

    /**
     * ⏭ Skip / ⏸ Pause / ▶️ Resume under a reminder. Returns the toast text.
     */
    public function handleButton(User $user, int|string $chatId, ?int $messageId, string $action, int $ruleId, ?string $ymd): string
    {
        $rule = $user->recurringTransactions()->with('tags')->whereKey($ruleId)->first();

        if (!$rule instanceof RecurringTransaction) {
            return 'Recurring payment not found';
        }

        Gate::forUser($user)->authorize('update', $rule);

        if ($action === 'skip') {
            // Only the occurrence this reminder was about: a second tap or an old reminder
            // must not skip the one after it.
            if (!$rule->active || $ymd === null || $rule->next_run_at->format('Ymd') !== $ymd) {
                $this->render($user, $chatId, $messageId, $rule, '🔔 Upcoming');

                return 'Already changed';
            }

            $skipped = $rule->next_run_at->copy();
            $rule->next_run_at = $rule->frequency->firstOnOrAfter($rule->starts_at, $skipped->copy()->addDay());

            if ($rule->ends_at && $rule->next_run_at->gt($rule->ends_at)) {
                $rule->active = false;
            }
            $rule->save();
            $this->history->outcome($chatId, $user, 'Skipped recurring payment on ' . $skipped->toDateString() . ': ' . $this->line($user, $rule));
            $this->render($user, $chatId, $messageId, $rule, '⏭ Skipped ' . UserFormatter::formatDate($user, $skipped));
            $this->sync->refreshRecurring($user);

            return 'Payment skipped!';
        }

        $active = $action === 'resume';

        if ($rule->active !== $active) {
            $rule = $this->updateRecurring->execute($user, $rule, ['active' => $active]);
            $this->history->outcome($chatId, $user, ($active ? 'Resumed' : 'Paused') . ' recurring rule: ' . $this->line($user, $rule));
        }

        $this->render($user, $chatId, $messageId, $rule, $active ? '▶️ Resumed' : '⏸ Paused');
        $this->sync->refreshRecurring($user);

        return $active ? 'Payment resumed!' : 'Payment paused!';
    }

    public function text(User $user, RecurringTransaction $rule, string $headline): string
    {
        $esc = fn (string $t): string => $this->support->escapeHtml($t);
        $lines = [
            $esc($headline),
            '',
            '<b>' . $esc($this->support->signedMoney($user, (float) $rule->amount)) . '</b>' . ($rule->note ? ' · ' . $esc($rule->note) : ''),
        ];

        $lines[] = $rule->active
            ? '📅 ' . $esc(UserFormatter::formatDate($user, $rule->next_run_at)) . ' · ' . $rule->frequency->value
            : '📅 Paused · ' . $rule->frequency->value;

        if ($rule->tags->isNotEmpty()) {
            $lines[] = '🏷 ' . $rule->tags->map(fn ($tag) => $esc(trim($tag->emoji . ' ' . $tag->title)))->implode(' · ');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function keyboard(RecurringTransaction $rule): array
    {
        if (!$rule->active) {
            return ['inline_keyboard' => [[['text' => '▶️ Resume', 'callback_data' => "rec:resume:{$rule->id}"]]]];
        }

        return ['inline_keyboard' => [[
            ['text' => '⏭ Skip', 'callback_data' => "rec:skip:{$rule->id}:" . $rule->next_run_at->format('Ymd')],
            ['text' => '⏸ Pause', 'callback_data' => "rec:pause:{$rule->id}"],
        ]]];
    }

    private function render(User $user, int|string $chatId, ?int $messageId, RecurringTransaction $rule, string $headline): void
    {
        if (!$messageId) {
            return;
        }

        // After Skip the card shows the next date; Pause leaves only ▶️ Resume.
        $keyboard = str_starts_with($headline, '⏭') ? ['inline_keyboard' => []] : $this->keyboard($rule);
        $this->client->editMessageText($chatId, $messageId, $this->text($user, $rule, $headline), $keyboard, TelegramSupport::PARSE_HTML);
    }

    private function line(User $user, RecurringTransaction $rule): string
    {
        return UserFormatter::formatMoney($user, (float) $rule->amount) . ($rule->note ? ' ' . $rule->note : '');
    }

    private function sentKey(RecurringTransaction $rule, string $date): string
    {
        return "recurring_reminded:{$rule->id}:{$date}";
    }
}
