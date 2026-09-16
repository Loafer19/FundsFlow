<?php

namespace App\Actions\Budgets;

use App\Channels\Telegram\TelegramClient;
use App\Models\Budget;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Cache;

class NotifyBudgetThresholdsAction
{
    private const THRESHOLDS = [100, 80];

    public function __construct(
        private readonly TelegramClient $client,
        private readonly CalculateBudgetPeriodSpentAction $calculateSpent,
    ) {}

    public function execute(): void
    {
        $budgets = Budget::query()
            ->whereHas('currentPeriod')
            ->with(['currentPeriod.tags', 'user.identities'])
            ->get();

        $messagesByChat = [];

        foreach ($budgets as $budget) {
            $period = $budget->currentPeriod;
            $user = $budget->user;

            if (!$period || (float) $period->amount <= 0 || !$user) {
                continue;
            }

            $asOf = $user->nowInTimezone();
            $spent = $this->calculateSpent->execute($period, $user, $asOf);
            $ratio = $spent / (float) $period->amount;

            $threshold = collect(self::THRESHOLDS)->first(fn (int $t) => $ratio >= $t / 100);

            if (!$threshold) {
                continue;
            }

            $bucketStart = $this->calculateSpent->currentBucketStart($period, $asOf)->toDateString();
            $cacheKey = "budget_notified:{$period->id}:{$bucketStart}:{$threshold}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            Cache::put($cacheKey, true, now()->addDays(400));

            $identity = $user->identities->firstWhere('provider', 'telegram');

            if (!$identity || ($identity->meta['muted'] ?? false)) {
                continue;
            }

            $label = $budget->title ?: $period->tags->map(fn ($tag) => $tag->emoji)->implode(' ');
            $emoji = $threshold >= 100 ? '🚨' : '⚠️';

            $messagesByChat[$identity->external_id][] = sprintf(
                '%s %s: %s / %s (%d%%)',
                $emoji,
                $label,
                UserFormatter::formatMoney($user, $spent),
                UserFormatter::formatMoney($user, (float) $period->amount),
                (int) round($ratio * 100),
            );
        }

        foreach ($messagesByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "Budget alert\n" . implode("\n", $lines));
        }
    }
}
