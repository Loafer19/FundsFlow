<?php

namespace App\Channels\Telegram;

use App\Actions\RecurringTransactions\GenerateRecurringTransactionsAction;
use App\Actions\RecurringTransactions\UpdateRecurringTransactionAction;
use App\Actions\Transactions\DeleteTransactionAction;
use App\Channels\Telegram\Widgets\TelegramWidgetSync;
use App\Enums\RecurringFrequency;
use App\Models\Identity;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\RecurringTypeData;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * A day before a recurring payment is added, one card per rule (rich, else classic HTML) with
 * ✓ Done (log it now), ⏰ +1 day (move this payment a day, reminded again), ⏭ Skip (this one)
 * and ⏸ Pause. Done, +1 day and Skip leave ↩️ Undo on the card for a day.
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
        private readonly GenerateRecurringTransactionsAction $generate,
        private readonly DeleteTransactionAction $deleteTransaction,
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
                    $this->client->sendRichOrHtml(
                        $identity->external_id,
                        $this->richText($user, $rule, '🔔 Tomorrow'),
                        $this->text($user, $rule, '🔔 Tomorrow'),
                        $this->keyboard($rule),
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
     * ✓ Done / ⏰ +1 day / ⏭ Skip / ⏸ Pause / ▶️ Resume / ↩️ Undo under a reminder. Returns the toast.
     */
    public function handleButton(User $user, int|string $chatId, ?int $messageId, string $action, int $ruleId, ?string $ymd): string
    {
        $rule = $user->recurringTransactions()->with(['tags', 'transactionType'])->whereKey($ruleId)->first();

        if (!$rule instanceof RecurringTransaction) {
            return 'Recurring payment not found';
        }

        Gate::forUser($user)->authorize('update', $rule);

        if ($action === 'undo') {
            return $this->undo($user, $chatId, $messageId, $rule);
        }

        if (in_array($action, ['done', 'snooze', 'skip'], true)) {
            // Only the occurrence this reminder was about: a second tap or an old reminder
            // must not act on the one after it.
            if (!$rule->active || $ymd === null || $rule->next_run_at->format('Ymd') !== $ymd) {
                $this->render($user, $chatId, $messageId, $rule, '🔔 Upcoming');

                return 'Already changed';
            }

            $before = ['next_run_at' => $rule->next_run_at->toDateString(), 'active' => $rule->active, 'data' => $rule->data];
            $due = $rule->next_run_at->copy();

            [$headline, $toast, $outcome, $transactionId] = match ($action) {
                'done' => $this->markDone($user, $rule, $due),
                'snooze' => $this->snooze($rule, $due),
                default => $this->skip($rule, $due),
            };

            $this->rememberUndo($chatId, $messageId, $rule, [...$before, 'transaction_id' => $transactionId]);
            $this->history->outcome($chatId, $user, $outcome . ': ' . $this->line($user, $rule));
            $this->render($user, $chatId, $messageId, $rule, $headline, ['inline_keyboard' => [[
                ['text' => '↩️ Undo', 'callback_data' => "rec:undo:{$rule->id}"],
            ]]]);
            $this->sync->refreshRecurring($user);

            return $toast;
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
            ? '📅 ' . $esc(TelegramSupport::cardDate($rule->next_run_at)) . ' · ' . $rule->frequency->value
            : '📅 Paused · ' . $rule->frequency->value;

        if ($rule->tags->isNotEmpty()) {
            $lines[] = TelegramSupport::tagLine($rule->tags->map(fn ($tag) => $esc(trim($tag->emoji . ' ' . $tag->title)))->implode(' · '));
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

        $ymd = $rule->next_run_at->format('Ymd');

        return ['inline_keyboard' => [
            [
                ['text' => '✓ Done', 'callback_data' => "rec:done:{$rule->id}:{$ymd}"],
                ['text' => '⏰ +1 day', 'callback_data' => "rec:snooze:{$rule->id}:{$ymd}"],
            ],
            [
                ['text' => '⏭ Skip', 'callback_data' => "rec:skip:{$rule->id}:{$ymd}"],
                ['text' => '⏸ Pause', 'callback_data' => "rec:pause:{$rule->id}"],
            ],
        ]];
    }

    /**
     * The same card as text() with a heading, a table and the tags as footer.
     */
    public function richText(User $user, RecurringTransaction $rule, string $headline): string
    {
        $esc = fn (string $t): string => RichCards::esc($t);
        $rows = [['Amount', '<b>' . $esc($this->support->signedMoney($user, (float) $rule->amount)) . '</b>']];

        if ($rule->note) {
            $rows[] = ['Note', $esc($rule->note)];
        }

        $type = RecurringTypeData::typeLine($rule);

        if ($type) {
            $rows[] = ['Type', $esc($type)];
        }

        $rows[] = $rule->active
            ? ['Next', '<code>' . $esc(TelegramSupport::cardDate($rule->next_run_at)) . '</code> · ' . $esc($rule->frequency->value)]
            : ['Next', 'Paused · ' . $esc($rule->frequency->value)];

        $rich = '<h4>' . $esc($headline) . '</h4>' . RichCards::table($rows);

        if ($rule->tags->isNotEmpty()) {
            $rich .= '<footer>' . $esc($rule->tags->map(fn ($tag) => trim($tag->emoji . ' ' . $tag->title))->implode(' · ')) . '</footer>';
        }

        return $rich;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: ?int} headline, toast, history line, transaction id
     */
    private function markDone(User $user, RecurringTransaction $rule, Carbon $due): array
    {
        $rule->setRelation('user', $user);
        $transaction = $this->generate->createOccurrence($rule, $user->todayDateString());

        if ($rule->ends_at && $rule->next_run_at->gt($rule->ends_at)) {
            $rule->update(['active' => false]);
        }

        return ['✅ Paid ' . TelegramSupport::cardDate($transaction->at), 'Payment logged!', 'Logged recurring payment due ' . $due->toDateString(), (int) $transaction->id];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: null}
     */
    private function snooze(RecurringTransaction $rule, Carbon $due): array
    {
        // Only this occurrence moves: the next one is still worked out from starts_at.
        $rule->next_run_at = $due->copy()->addDay();
        $rule->save();

        return ['⏰ Moved to ' . TelegramSupport::cardDate($rule->next_run_at), 'Payment snoozed!', 'Moved recurring payment from ' . $due->toDateString() . ' to ' . $rule->next_run_at->toDateString(), null];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: null}
     */
    private function skip(RecurringTransaction $rule, Carbon $due): array
    {
        $rule->next_run_at = $rule->frequency->firstOnOrAfter($rule->starts_at, $due->copy()->addDay());

        if ($rule->ends_at && $rule->next_run_at->gt($rule->ends_at)) {
            $rule->active = false;
        }
        $rule->save();

        return ['⏭ Skipped ' . TelegramSupport::cardDate($due), 'Payment skipped!', 'Skipped recurring payment on ' . $due->toDateString(), null];
    }

    /**
     * ↩️ Undo: the rule's date, state and fields as before the tap; a logged payment is deleted.
     */
    private function undo(User $user, int|string $chatId, ?int $messageId, RecurringTransaction $rule): string
    {
        $state = $messageId ? Cache::pull($this->undoKey($chatId, $messageId, $rule)) : null;

        if (!is_array($state)) {
            $this->render($user, $chatId, $messageId, $rule, $rule->active ? '🔔 Upcoming' : '⏸ Paused');

            return 'Nothing to undo';
        }

        if (is_int($state['transaction_id'] ?? null)) {
            $transaction = $user->transactions()->whereKey($state['transaction_id'])->first();

            if ($transaction instanceof Transaction) {
                $this->deleteTransaction->execute($user, $transaction);
            }
        }

        $rule->forceFill([
            'next_run_at' => $state['next_run_at'],
            'active' => (bool) $state['active'],
            'data' => $state['data'],
        ])->save();

        $this->history->outcome($chatId, $user, 'Undid the reminder action: ' . $this->line($user, $rule));
        $this->render($user, $chatId, $messageId, $rule->refresh(), '🔔 Upcoming');
        $this->sync->refreshRecurring($user);

        return 'Change undone!';
    }

    /**
     * @param array{next_run_at: string, active: bool, data: mixed, transaction_id: ?int} $state
     */
    private function rememberUndo(int|string $chatId, ?int $messageId, RecurringTransaction $rule, array $state): void
    {
        if ($messageId) {
            Cache::put($this->undoKey($chatId, $messageId, $rule), $state, now()->addDay());
        }
    }

    private function undoKey(int|string $chatId, int $messageId, RecurringTransaction $rule): string
    {
        return "recurring_undo:{$chatId}:{$messageId}:{$rule->id}";
    }

    /**
     * @param null|array<string, mixed> $keyboard default: the rule's buttons (Pause leaves only ▶️ Resume)
     */
    private function render(User $user, int|string $chatId, ?int $messageId, RecurringTransaction $rule, string $headline, ?array $keyboard = null): void
    {
        if (!$messageId) {
            return;
        }

        $this->client->editRichOrHtml(
            $chatId,
            $messageId,
            $this->richText($user, $rule, $headline),
            $this->text($user, $rule, $headline),
            $keyboard ?? $this->keyboard($rule),
        );
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
