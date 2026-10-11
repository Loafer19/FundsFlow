<?php

namespace App\Actions\RecurringTransactions;

use App\Enums\RecurringFrequency;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Support\TagIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class UpdateRecurringTransactionAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, RecurringTransaction $recurringTransaction, array $data): RecurringTransaction
    {
        Gate::forUser($user)->authorize('update', $recurringTransaction);

        // PATCH without `tags` must keep the current tags; only an explicit key (even [] / null) syncs.
        $syncTags = array_key_exists('tags', $data);
        $tagIds = $syncTags
            ? TagIds::forSync($user, $data['tags'] ?? [], $recurringTransaction->tags()->pluck('tags.id'))
            : [];
        unset($data['tags']);

        $startsAt = Carbon::parse($data['starts_at'] ?? $recurringTransaction->starts_at);
        $frequency = isset($data['frequency'])
            ? RecurringFrequency::from($data['frequency'])
            : $recurringTransaction->frequency;

        $resuming = ($data['active'] ?? false) && !$recurringTransaction->active;
        $scheduleChanged = $startsAt->toDateString() !== $recurringTransaction->starts_at->toDateString()
            || $frequency !== $recurringTransaction->frequency;
        $neverRan = $recurringTransaction->next_run_at->toDateString()
            === $recurringTransaction->starts_at->toDateString();

        if ($scheduleChanged && $neverRan && !$resuming) {
            // Not materialized yet — behave like a fresh rule (backfills a past start).
            $data['next_run_at'] = $startsAt->toDateString();
        } elseif ($resuming || $scheduleChanged) {
            // Resume or reschedule on the rule's own cadence from today, without
            // materializing occurrences missed while it was paused.
            $data['next_run_at'] = $frequency
                ->firstOnOrAfter($startsAt, $user->nowInTimezone())
                ->toDateString();
        }

        $recurringTransaction->update($data);
        if ($syncTags) {
            $recurringTransaction->tags()->sync($tagIds);
        }

        return $recurringTransaction->load('tags');
    }
}
