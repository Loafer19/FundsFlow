<?php

namespace App\Actions\Budgets;

use App\Channels\Telegram\TelegramClient;
use App\Enums\BudgetLength;
use App\Models\Budget;
use App\Models\BudgetNotification;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use Carbon\Carbon;

class NotifyBudgetThresholdsAction
{
    // Checked highest-first so a period that jumps straight past 80% to
    // over 100% between runs only ever gets the more informative alert.
    private const THRESHOLDS = [100, 80];

    public function __construct(private readonly TelegramClient $client) {}

    public function execute(): void
    {
        $budgets = Budget::query()
            ->whereHas('currentPeriod')
            ->with(['currentPeriod.tags', 'user.identities'])
            ->get();

        $messagesByChat = [];

        foreach ($budgets as $budget) {
            $period = $budget->currentPeriod;

            if (!$period || (float) $period->amount <= 0) {
                continue;
            }

            $spent = $this->spent($period, $budget->user_id);
            $ratio = $spent / (float) $period->amount;

            $threshold = collect(self::THRESHOLDS)->first(fn (int $t) => $ratio >= $t / 100);

            if (!$threshold) {
                continue;
            }

            $bucketStart = $this->currentBucketStart($period)->toDateString();

            $alreadySent = BudgetNotification::query()
                ->where('budget_period_id', $period->id)
                ->where('bucket_start', $bucketStart)
                ->where('threshold', $threshold)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            BudgetNotification::create([
                'budget_period_id' => $period->id,
                'threshold' => $threshold,
                'bucket_start' => $bucketStart,
            ]);

            $identity = $budget->user->identities->firstWhere('provider', 'telegram');

            if (!$identity || ($identity->meta['muted'] ?? false)) {
                continue;
            }

            $label = $budget->title ?: $period->tags->map(fn ($tag) => $tag->emoji)->implode(' ');
            $emoji = $threshold >= 100 ? '🚨' : '⚠️';

            $messagesByChat[$identity->external_id][] = sprintf(
                '%s %s: %.2f / %.2f (%d%%)',
                $emoji,
                $label,
                $spent,
                (float) $period->amount,
                (int) round($ratio * 100),
            );
        }

        foreach ($messagesByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "Budget alert\n" . implode("\n", $lines));
        }
    }

    private function currentBucketStart(BudgetPeriod $period): Carbon
    {
        $boundary = match ($period->length) {
            BudgetLength::Week => now()->startOfWeek(),
            BudgetLength::Month => now()->startOfMonth(),
            BudgetLength::Year => now()->startOfYear(),
        };

        return $boundary->greaterThan($period->starts_at) ? $boundary : $period->starts_at->copy();
    }

    private function spent(BudgetPeriod $period, int $userId): float
    {
        $tagIds = $period->tags->pluck('id');

        if ($tagIds->isEmpty()) {
            return 0.0;
        }

        $sum = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('at', [$this->currentBucketStart($period), now()])
            ->where('amount', '<', 0)
            ->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds))
            ->sum('amount');

        return (float) $sum * -1;
    }
}
